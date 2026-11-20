<?php

declare(strict_types=1);

namespace App\Application\Campaigns;

use App\Application\WhatsApp\MarketingMessages;
use App\Application\WhatsApp\NumberAccess;
use App\Domain\Audit\AuditLogger;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Exceptions\CampaignException;
use App\Domain\Campaigns\Jobs\LaunchCampaign;
use App\Domain\Campaigns\Jobs\SendCampaignBatch;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Campaigns\Services\AbTest;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Crm\Models\Segment;
use App\Domain\Crm\Services\SegmentQuery;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Pricing\CostEstimator;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Support\Meta\MetaErrorCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Campaign lifecycle: draft → (scheduled) → sending → completed, or cancelled / failed.
 * A campaign's audience is "segment rule AND consent": the segment decides who fits, consent
 * decides who is allowed — both counts are shown before anything is sent.
 */
final class ManageCampaigns
{
    /** Platform ceiling, whatever the plan says. */
    public const MAX_RATE_PER_HOUR = 20000;

    public const DEFAULT_RATE_PER_HOUR = 500;

    private const MEDIA_HEADERS = ['IMAGE' => 'image', 'VIDEO' => 'video', 'DOCUMENT' => 'document'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly NumberAccess $numbers,
        private readonly SegmentQuery $segments,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data validated: name, phone_number_id, template_id, variables, media_id, segment_id */
    public function save(?Campaign $campaign, array $data): Campaign
    {
        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::Broadcasts);
        if ($campaign !== null && ! $campaign->status->isEditable()) {
            throw CampaignException::notEditable($campaign->status->value);
        }

        $number = PhoneNumber::query()->findOrFail($data['phone_number_id']);
        $membership = $this->context->membership();
        if ($membership !== null) {
            $granted = $this->numbers->grantedIds($membership->loadMissing('role'));
            if ($granted !== null && ! in_array($number->id, $granted, true)) {
                throw ValidationException::withMessages(['phone_number_id' => 'You do not have access to this number.']);
            }
        }

        $message = $this->message($number, $data['template_id'] ?? null, (array) ($data['variables'] ?? []), $data['media_id'] ?? null);
        $template = $message['template'];
        $abTest = $this->abTest($number, $template, $data['ab_test'] ?? null);

        $segment = isset($data['segment_id']) ? Segment::query()->findOrFail($data['segment_id']) : null;

        $campaign ??= new Campaign(['created_by_membership_id' => $membership?->id]);
        $campaign->fill([
            'name' => trim((string) $data['name']),
            'phone_number_id' => $number->id,
            'message_template_id' => $template->id,
            'template_name' => $template->name,
            'template_language' => $template->language,
            'template_category' => $template->category,
            'variables' => $message['variables'],
            'media_id' => $message['media_id'],
            'segment_id' => $segment?->id,
            'audience_tag' => isset($data['audience_tag']) && trim((string) $data['audience_tag']) !== '' ? trim((string) $data['audience_tag']) : null,
            'audience_filter' => $this->segments->filter($data['filter'] ?? null),
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'objective' => $data['objective'] ?? null,
            // Soft consent (Batch 7): unknown contacts are sent to after the tick, so there is no opted-in-only switch.
            'opted_in_only' => false,
            'ab_test' => $abTest,
        ])->save();

        $this->audit->record($campaign->wasRecentlyCreated ? 'campaign.created' : 'campaign.updated', $campaign, after: ['name' => $campaign->name]);

        return $campaign;
    }

    /**
     * One message of a campaign (the campaign's own, or variant B of an A/B test): an approved template
     * of the number's account with every variable filled in and the header file it needs.
     *
     * @param  array<string, mixed>  $input
     * @return array{template: MessageTemplate, variables: array{header: list<string>, body: list<string>, buttons: array<string, string>}, media_id: ?string}
     */
    private function message(PhoneNumber $number, mixed $templateId, array $input, mixed $mediaInput, string $prefix = ''): array
    {
        /** @var ?MessageTemplate $template */
        $template = MessageTemplate::query()->where('waba_account_id', $number->waba_account_id)->find($templateId);
        if ($template === null) {
            throw ValidationException::withMessages([$prefix.'template_id' => 'Choose a template of this WhatsApp number.']);
        }
        if (! $template->isSendable()) {
            throw ValidationException::withMessages([$prefix.'template_id' => 'Only approved templates can be sent in a campaign.']);
        }

        $definition = $template->variables();
        $variables = [
            'header' => array_values(array_map('strval', (array) ($input['header'] ?? []))),
            'body' => array_values(array_map('strval', (array) ($input['body'] ?? []))),
            'buttons' => array_map('strval', (array) ($input['buttons'] ?? [])),
        ];
        foreach (['header', 'body'] as $part) {
            $filled = array_filter($variables[$part], fn (string $v) => trim($v) !== '');
            if (count($filled) !== count($definition[$part]) || count($variables[$part]) !== count($definition[$part])) {
                throw ValidationException::withMessages([$prefix."variables.{$part}" => "Fill in every {$part} variable of the template (you can use contact fields such as {{first_name}})."]);
            }
        }
        foreach ($definition['buttons'] as $button) {
            if ($button['variable'] && $button['type'] === 'URL' && trim((string) ($variables['buttons'][(string) $button['index']] ?? '')) === '') {
                throw ValidationException::withMessages([$prefix.'variables.buttons' => "Fill in the link value for the \"{$button['text']}\" button."]);
            }
        }

        $mediaId = null;
        $headerMedia = self::MEDIA_HEADERS[$definition['header_format'] ?? ''] ?? null;
        if ($headerMedia !== null) {
            $media = $mediaInput !== null ? Media::query()->find($mediaInput) : null;
            if ($media === null || Media::whatsappTypeFor((string) $media->mime_type) !== $headerMedia) {
                throw ValidationException::withMessages([$prefix.'media_id' => "This template needs a header {$headerMedia}. Attach one."]);
            }
            $mediaId = $media->id;
        }

        return ['template' => $template, 'variables' => $variables, 'media_id' => $mediaId];
    }

    /**
     * The A/B test settings, validated: variant B uses its own approved template of the same category
     * (so both go to the same audience), except a send-time test, which sends the same template later.
     *
     * @return ?array<string, mixed>
     */
    private function abTest(PhoneNumber $number, MessageTemplate $templateA, mixed $input): ?array
    {
        if (! is_array($input) || ! ($input['enabled'] ?? false)) {
            return null;
        }
        $kind = (string) ($input['kind'] ?? '');
        if (! in_array($kind, AbTest::KINDS, true)) {
            throw ValidationException::withMessages(['ab_test.kind' => 'Choose what to test: template text, button or send time.']);
        }
        $goal = (string) ($input['goal'] ?? 'reply');
        if (! in_array($goal, AbTest::GOALS, true)) {
            throw ValidationException::withMessages(['ab_test.goal' => 'Choose the goal: reply rate, button click rate, leads or purchases.']);
        }
        $percent = (int) ($input['test_percent'] ?? 100);
        if (! in_array($percent, [20, 100], true)) {
            throw ValidationException::withMessages(['ab_test.test_percent' => 'Test on the whole list, or on 20% and send the winner to the other 80%.']);
        }

        $b = null;
        $delay = null;
        if ($kind === 'send_time') {
            $delay = (int) ($input['b_delay_hours'] ?? 0);
            if ($delay < 1 || $delay > 72) {
                throw ValidationException::withMessages(['ab_test.b_delay_hours' => 'Send variant B between 1 and 72 hours after variant A.']);
            }
        } else {
            $message = $this->message($number, $input['template_id'] ?? null, (array) ($input['variables'] ?? []), $input['media_id'] ?? null, 'ab_test.');
            $tb = $message['template'];
            if (strtoupper((string) $tb->category) !== strtoupper((string) $templateA->category)) {
                throw ValidationException::withMessages(['ab_test.template_id' => 'Both variants need templates of the same category, so they go to the same audience.']);
            }
            if ($tb->id === $templateA->id) {
                throw ValidationException::withMessages(['ab_test.template_id' => 'Choose a different template for variant B.']);
            }
            $b = ['message_template_id' => $tb->id, 'template_name' => $tb->name, 'template_language' => $tb->language, 'template_category' => $tb->category,
                'variables' => $message['variables'], 'media_id' => $message['media_id']];
        }

        return [
            'kind' => $kind,
            'goal' => $goal,
            'min_sample' => max(10, min(100000, (int) ($input['min_sample'] ?? AbTest::DEFAULT_MIN_SAMPLE))),
            'test_percent' => $percent,
            'auto_send_winner' => $percent < 100 && (bool) ($input['auto_send_winner'] ?? false),
            'b' => $b,
            'b_delay_hours' => $delay,
            'rest' => null,
        ];
    }

    /** Send now, or at $at (which requires the scheduling feature). */
    public function launch(Campaign $campaign, ?Carbon $at = null, bool $consentConfirmed = false): Campaign
    {
        $tenant = $this->context->tenant();
        $this->entitlements->ensureEnabled($tenant, FeatureKey::Broadcasts);
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Scheduled], true)) {
            throw CampaignException::notEditable($campaign->status->value);
        }

        $number = PhoneNumber::query()->find($campaign->phone_number_id);
        if ($number === null || $number->status !== PhoneNumberStatus::Connected) {
            throw CampaignException::cannot('The WhatsApp number of this campaign is not connected. Reconnect it from Channels.');
        }
        $template = $campaign->message_template_id !== null ? MessageTemplate::query()->find($campaign->message_template_id) : null;
        if ($template === null || ! $template->isSendable()) {
            throw CampaignException::cannot('The template of this campaign is no longer approved on Meta. Choose another template.');
        }

        // Freeze the audience rule now; who matches it is still evaluated at send time.
        $segment = $campaign->segment_id !== null ? Segment::query()->find($campaign->segment_id) : null;
        if ($campaign->segment_id !== null && $segment === null) {
            throw CampaignException::cannot('The segment of this campaign was deleted. Choose the audience again.');
        }
        $audience = ['match' => $segment->match ?? 'all', 'rules' => $segment->rules ?? [], 'name' => $segment?->name, 'tag' => $campaign->audience_tag,
            'filter' => $campaign->getAttribute('audience_filter')];

        // Meta may have re-categorised the template since the campaign was drafted: price and filter by what it is now.
        if ($template->category !== null && strtoupper((string) $template->category) !== strtoupper((string) $campaign->template_category)) {
            $campaign->forceFill(['template_category' => strtoupper((string) $template->category)]);
        }

        $counts = $this->counts($audience, $campaign->template_category, $campaign->opted_in_only);
        if ($counts['eligible'] === 0) {
            throw CampaignException::cannot('Nobody in this audience can be messaged: '.($counts['matched'] === 0 ? 'no contact matches the segment.'
                : (strtoupper((string) $campaign->template_category) === 'MARKETING' ? 'all the matching contacts opted out, stopped marketing messages or have no WhatsApp number.' : 'none of the matching contacts has a WhatsApp number.')));
        }
        // The plan's monthly reach is checked before the first message, never mid-campaign.
        $this->entitlements->ensureWithinLimit($tenant, FeatureKey::CampaignReachMonthly, $counts['eligible']);

        // Marketing to contacts with no consent record: allowed (a soft guardrail), but the sender confirms
        // the contacts agreed, and the confirmation is kept in the Audit log.
        $unknown = $counts['consent'] !== null ? $counts['consent']['unknown'] : 0;
        $needsConfirmation = strtoupper((string) $campaign->template_category) === 'MARKETING' && ! $campaign->opted_in_only && $unknown > 0;
        if ($needsConfirmation && ! $consentConfirmed) {
            throw ValidationException::withMessages(['consent_confirmed' => "{$unknown} contacts have no consent record. Confirm these contacts agreed to receive messages from your business."]);
        }

        $scheduled = $at !== null && $at->isFuture();
        if ($scheduled) {
            $this->entitlements->ensureEnabled($tenant, FeatureKey::CampaignScheduling);
        }

        $campaign->forceFill([
            'audience' => $audience,
            // The sending speed is fixed at launch from the workspace's plan.
            'batch_per_hour' => $this->sendRatePerHour(),
            'matched_count' => $counts['matched'],
            'eligible_count' => $counts['eligible'],
            'status' => $scheduled ? CampaignStatus::Scheduled : CampaignStatus::Sending,
            'scheduled_at' => $scheduled ? $at : null,
            'started_at' => $scheduled ? null : now(),
            'failure_reason' => null,
            // What Meta will charge, as shown at launch, with the date of the rates used.
            'cost_estimate' => app(CostEstimator::class)->estimate($this->eligibleQuery($audience, $campaign->template_category, $campaign->opted_in_only), $campaign->template_category, $number->id),
            'consent_confirmation' => $needsConfirmation ? ['membership_id' => $this->context->membership()?->id, 'at' => now()->toIso8601String(), 'unknown' => $unknown] : null,
        ])->save();

        if ($needsConfirmation) {
            $this->audit->record('campaign.consent_confirmed', $campaign, meta: ['campaign' => $campaign->name, 'unknown_consent' => $unknown,
                'statement' => 'I confirm these contacts agreed to receive messages from my business']);
        }

        $this->audit->record($scheduled ? 'campaign.scheduled' : 'campaign.launched', $campaign, meta: ['matched' => $counts['matched'], 'eligible' => $counts['eligible']] + $counts['excluded']);

        if (! $scheduled) {
            LaunchCampaign::dispatch($campaign->id)->afterCommit();
        }

        return $campaign;
    }

    /** Stops a scheduled campaign, or a running one: messages already handed to WhatsApp still go out. */
    public function cancel(Campaign $campaign): Campaign
    {
        $holding = $campaign->status === CampaignStatus::Completed && CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'held')->exists();
        if (! $holding && ! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Paused], true)) {
            throw CampaignException::cannot('Only a scheduled, sending or paused campaign can be stopped.');
        }

        $wasScheduled = $campaign->status === CampaignStatus::Scheduled;
        CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereIn('status', ['pending', 'held'])
            ->update(['status' => 'skipped', 'reason' => 'Campaign stopped', 'updated_at' => now()]);
        $campaign->forceFill(match (true) {
            $wasScheduled => ['status' => CampaignStatus::Draft, 'scheduled_at' => null],
            // A/B test: the test was sent; the rest of the list will not be.
            $holding => ['ab_test' => array_merge((array) $campaign->ab_test, ['rest' => ['variant' => null, 'at' => now()->toIso8601String(), 'stopped' => true]])],
            default => ['status' => CampaignStatus::Cancelled, 'completed_at' => now()],
        })->save();
        $this->audit->record('campaign.cancelled', $campaign);

        return $campaign;
    }

    public function delete(Campaign $campaign): void
    {
        if (in_array($campaign->status, [CampaignStatus::Sending, CampaignStatus::Scheduled, CampaignStatus::Paused], true)) {
            throw CampaignException::cannot('Stop the campaign before deleting it.');
        }
        $this->audit->record('campaign.deleted', $campaign, meta: ['name' => $campaign->name]);
        $campaign->delete();
    }

    /** Pause a running campaign (by a person, or automatically when the number's quality drops). */
    public function pause(Campaign $campaign, string $reason = 'Paused by your team'): Campaign
    {
        if ($campaign->status !== CampaignStatus::Sending) {
            throw CampaignException::cannot('Only a campaign that is sending can be paused.');
        }
        $campaign->forceFill(['status' => CampaignStatus::Paused, 'paused_at' => now(), 'pause_reason' => mb_substr($reason, 0, 190), 'next_batch_at' => null, 'wait_reason' => null])->save();
        $this->audit->record('campaign.paused', $campaign, meta: ['reason' => $reason]);

        return $campaign;
    }

    public function resume(Campaign $campaign): Campaign
    {
        if ($campaign->status !== CampaignStatus::Paused) {
            throw CampaignException::cannot('Only a paused campaign can be resumed.');
        }
        $number = PhoneNumber::query()->find($campaign->phone_number_id);
        if ($number === null || $number->status !== PhoneNumberStatus::Connected) {
            throw CampaignException::cannot('The WhatsApp number of this campaign is not connected.');
        }
        if (strtoupper((string) $number->quality_rating) === 'RED') {
            throw CampaignException::cannot('This number\'s quality rating is still Red. Wait until Meta raises it before sending more marketing.');
        }

        $campaign->forceFill(['status' => CampaignStatus::Sending, 'paused_at' => null, 'pause_reason' => null])->save();
        $this->audit->record('campaign.resumed', $campaign);
        SendCampaignBatch::dispatch($campaign->id)->afterCommit();

        return $campaign;
    }

    /**
     * A/B test on 20% of the list: send one variant to the 80% held back. Normally the declared winner;
     * the owner may choose a variant without a clear winner (recorded as their choice in the Audit log).
     */
    public function sendRest(Campaign $campaign, string $variant, bool $auto = false): Campaign
    {
        $ab = $campaign->ab_test;
        if (! AbTest::enabled($campaign) || (int) ($ab['test_percent'] ?? 100) >= 100) {
            throw CampaignException::cannot('This campaign has no list held back for the winner.');
        }
        if (($ab['rest'] ?? null) !== null) {
            throw CampaignException::cannot('The rest of the list was already sent variant '.$ab['rest']['variant'].'.');
        }
        if (! in_array($variant, ['A', 'B'], true)) {
            throw ValidationException::withMessages(['variant' => 'Choose variant A or B.']);
        }
        if ($campaign->status !== CampaignStatus::Completed) {
            throw CampaignException::cannot('Wait until the test has been sent to everyone in it.');
        }
        $results = app(AbTest::class)->results($campaign);
        $number = PhoneNumber::query()->find($campaign->phone_number_id);
        if ($number === null || $number->status !== PhoneNumberStatus::Connected) {
            throw CampaignException::cannot('The WhatsApp number of this campaign is not connected.');
        }

        $held = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'held')
            ->update(['status' => 'pending', 'variant' => $variant, 'updated_at' => now()]);
        $campaign->forceFill([
            'ab_test' => array_merge((array) $ab, ['rest' => ['variant' => $variant, 'at' => now()->toIso8601String(), 'recipients' => $held,
                'winner' => $results['winner'], 'chosen_by' => $auto ? null : $this->context->membership()?->id, 'auto' => $auto]]),
            'status' => CampaignStatus::Sending,
            'completed_at' => null,
        ])->save();
        $this->audit->record('campaign.ab_rest_sent', $campaign, meta: ['variant' => $variant, 'recipients' => $held,
            'winner' => $results['winner'], 'reason' => $auto ? 'Winner sent automatically' : ($results['winner'] === $variant ? 'Winner' : 'Chosen by the owner without a clear winner')]);
        SendCampaignBatch::dispatch($campaign->id)->afterCommit();

        return $campaign;
    }

    /** Quality protection: stop every running campaign on a number Meta has just flagged. */
    public function autoPauseForNumber(PhoneNumber $number, string $reason): int
    {
        $running = Campaign::query()->where('phone_number_id', $number->id)->where('status', CampaignStatus::Sending->value)->get();
        foreach ($running as $campaign) {
            $this->pause($campaign, $reason);
        }

        return $running->count();
    }

    /** A new draft with the same template, audience and settings. */
    public function duplicate(Campaign $campaign): Campaign
    {
        $this->entitlements->ensureEnabled($this->context->tenant(), FeatureKey::Broadcasts);

        $copy = Campaign::query()->create(array_merge(
            $campaign->only(['phone_number_id', 'message_template_id', 'template_name', 'template_language', 'template_category', 'variables', 'media_id', 'segment_id', 'audience_tag', 'audience_filter', 'notes', 'objective', 'opted_in_only']),
            ['ab_test' => is_array($campaign->ab_test) ? array_merge($campaign->ab_test, ['rest' => null]) : null],
            ['name' => mb_substr($campaign->name, 0, 112).' (copy)', 'status' => CampaignStatus::Draft, 'created_by_membership_id' => $this->context->membership()?->id],
        ));
        $this->audit->record('campaign.duplicated', $copy, meta: ['from' => $campaign->id]);

        return $copy;
    }

    /**
     * Who matches, who will receive it, and why the others will not — shown on the campaign before it
     * is sent ("12 people won't receive this marketing message: 9 opted out, 3 stopped marketing").
     * Each excluded contact is counted under the first reason that applies, in this order.
     *
     * @param  array{match?: string, rules?: array<int, array<string, mixed>>, tag?: ?string, filter?: mixed}|null  $audience
     * @return array{matched: int, eligible: int, excluded: array<string, int>, consent: ?array{opted_in: int, unknown: int, opted_out: int}}
     */
    public function counts(?array $audience, ?string $category, bool $optedInOnly = false): array
    {
        $matched = $this->audienceQuery($audience)->count();
        $eligible = $this->eligibleQuery($audience, $category, $optedInOnly)->count();
        $withNumber = fn () => $this->audienceQuery($audience)->where(fn (Builder $w) => $w->whereNotNull('wa_id')->orWhereNotNull('bsuid'));
        $excluded = ['no_number' => $matched - $withNumber()->count(), 'opted_out' => 0, 'stopped_marketing' => 0, 'frequency_cap' => 0];
        /** @var ?array{opted_in: int, unknown: int, opted_out: int} $consent */
        $consent = null;
        if (strtoupper((string) $category) === 'MARKETING') {
            $excluded['opted_out'] = $withNumber()->where('consent_state', ConsentState::OptedOut->value)->count();
            $excluded['stopped_marketing'] = $withNumber()->where('consent_state', '!=', ConsentState::OptedOut->value)->where('marketing_opted_out', true)->count();
            // Marketing consent of everyone with a number: opted in, unknown (no record), opted out (always excluded).
            $optedIn = $withNumber()->where('consent_state', ConsentState::OptedIn->value)->where('marketing_opted_out', false)->count();
            $unknown = $withNumber()->where('consent_state', ConsentState::Unknown->value)->where('marketing_opted_out', false)->count();
            $consent = ['opted_in' => $optedIn, 'unknown' => $unknown, 'opted_out' => $excluded['opted_out'] + $excluded['stopped_marketing']];
            $excluded['no_consent_record'] = $optedInOnly ? $unknown : 0;
            $excluded['frequency_cap'] = max(0, $matched - $eligible - $excluded['no_number'] - $excluded['opted_out'] - $excluded['stopped_marketing'] - $excluded['no_consent_record']);
        }

        return ['matched' => $matched, 'eligible' => $eligible, 'excluded' => $excluded, 'consent' => $consent];
    }

    /**
     * Frequency cap: which of these contacts already received the workspace's maximum number of
     * marketing campaign messages in the last 7 days.
     *
     * @param  list<string>  $contactIds
     * @return array<string, bool> contact id → true
     */
    public function cappedContactIds(array $contactIds, ?string $exceptCampaignId = null): array
    {
        $cap = ComplianceSettings::for($this->context->tenant())->marketingFrequencyCap;
        if ($cap <= 0 || $contactIds === []) {
            return [];
        }

        return CampaignRecipient::query()
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->whereIn('campaign_recipients.contact_id', $contactIds)
            ->where('campaign_recipients.status', 'queued')
            ->where('campaign_recipients.updated_at', '>=', now()->subDays(7))
            ->where('campaigns.template_category', 'MARKETING')
            ->when($exceptCampaignId, fn ($q) => $q->where('campaigns.id', '!=', $exceptCampaignId))
            ->groupBy('campaign_recipients.contact_id')
            ->havingRaw('count(*) >= ?', [$cap])
            ->pluck('campaign_recipients.contact_id')
            ->mapWithKeys(fn (string $id) => [$id => true])
            ->all();
    }

    /**
     * How fast campaigns of this workspace are sent (messages per hour). Set by the plan — never
     * chosen by the sender — so nobody can pick a speed that hurts their number or our platform.
     */
    public function sendRatePerHour(): int
    {
        $entitlement = $this->entitlements->for($this->context->tenant())->get(FeatureKey::CampaignSendRatePerHour);

        return match (true) {
            $entitlement->isUnlimited() => self::MAX_RATE_PER_HOUR,
            $entitlement->enabled && (int) $entitlement->limit > 0 => min(self::MAX_RATE_PER_HOUR, (int) $entitlement->limit),
            default => self::DEFAULT_RATE_PER_HOUR,
        };
    }

    /**
     * Segment rules AND tag AND the campaign's own conditions (custom fields included).
     *
     * @param  array{match?: string, rules?: array<int, array<string, mixed>>, tag?: ?string, filter?: ?array{match?: string, rules?: array<int, array<string, mixed>>}}|null  $audience
     * @return Builder<Contact>
     */
    public function audienceQuery(?array $audience): Builder
    {
        $query = $this->segments->apply(Contact::query(), (string) ($audience['match'] ?? 'all'), (array) ($audience['rules'] ?? []));
        $filter = $audience['filter'] ?? null;
        if (is_array($filter) && ($filter['rules'] ?? []) !== []) {
            $query = $this->segments->apply($query, (string) ($filter['match'] ?? 'all'), (array) $filter['rules']);
        }
        $tag = $audience['tag'] ?? null;

        return is_string($tag) && $tag !== '' ? $query->whereRaw('tags @> ARRAY[?]::text[]', [$tag]) : $query;
    }

    /**
     * The SQL twin of Contact::campaignBlocker(), for counting before launch.
     *
     * @param  array{match?: string, rules?: array<int, array<string, mixed>>, tag?: ?string, filter?: mixed}|null  $audience
     * @return Builder<Contact>
     */
    public function eligibleQuery(?array $audience, ?string $category, bool $optedInOnly = false): Builder
    {
        $query = $this->audienceQuery($audience)
            ->where(fn (Builder $w) => $w->whereNotNull('wa_id')->orWhereNotNull('bsuid'));

        if (strtoupper((string) $category) === 'MARKETING') {
            // Unknown and opted-in contacts receive marketing; never anyone who opted out (STOP) or
            // opted out of marketing. With "Send only to opted-in contacts", only recorded opt-ins.
            // Utility and authentication go to everyone.
            $query->where('consent_state', $optedInOnly ? '=' : '!=', $optedInOnly ? ConsentState::OptedIn->value : ConsentState::OptedOut->value)
                ->where('marketing_opted_out', false);

            $cap = ComplianceSettings::for($this->context->tenant())->marketingFrequencyCap;
            if ($cap > 0) {
                $query->whereRaw("(SELECT count(*) FROM campaign_recipients cr JOIN campaigns c ON c.id = cr.campaign_id
                    WHERE cr.contact_id = contacts.id AND cr.status = 'queued' AND cr.updated_at >= ? AND c.template_category = 'MARKETING') < ?", [now()->subDays(7), $cap]);
            }
        }

        return $query;
    }

    /**
     * Pipeline numbers for one campaign: what happened before WhatsApp (recipients) and after
     * (message delivery statuses, cumulative: a read message was also delivered and sent).
     *
     * @return array<string, mixed>
     */
    public function stats(Campaign $campaign): array
    {
        $recipients = CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');
        $messages = Message::query()->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');

        $m = fn (string ...$statuses) => (int) array_sum(array_map(fn (string $s) => (int) ($messages[$s] ?? 0), $statuses));
        // Meta's per-person marketing limit: not a failure of the business or the number, shown on its own.
        $marketingLimit = Message::query()->where('campaign_id', $campaign->id)->where('status', 'failed')
            ->where('error_code', MetaErrorCatalog::PER_PERSON_MARKETING_LIMIT)->count();

        return [
            'matched' => $campaign->matched_count,
            'eligible' => $campaign->eligible_count,
            'pending' => (int) ($recipients['pending'] ?? 0),
            'skipped' => (int) ($recipients['skipped'] ?? 0),
            // A/B test on 20%: the rest of the list, waiting for the winner.
            'held' => (int) ($recipients['held'] ?? 0),
            'queued' => $m('queued'),
            'sent' => $m('accepted', 'sent', 'delivered', 'read'),
            'delivered' => $m('delivered', 'read'),
            'read' => $m('read'),
            'failed' => $m('failed') - $marketingLimit + (int) ($recipients['failed'] ?? 0),
            'marketing_limit' => $marketingLimit,
            'replied' => $this->replied($campaign),
            // Which API delivered the campaign's messages (Marketing Messages API vs Cloud API).
            'routes' => $this->routes($campaign),
            // Marketing: delivery, blocks and replies by the contact's consent when the campaign was prepared.
            'by_consent' => strtoupper((string) $campaign->template_category) === 'MARKETING' ? $this->byConsent($campaign) : null,
            // A/B test: per-variant results of the test recipients, and the verdict.
            'ab' => AbTest::enabled($campaign) ? app(AbTest::class)->results($campaign) : null,
        ];
    }

    /**
     * Per marketing-consent state: messages sent and delivered, people who blocked (opted out or
     * stopped marketing within 72 hours of the message) and people who replied within 72 hours.
     *
     * @return array<string, array{sent: int, delivered: int, blocked: int, replied: int}>
     */
    private function byConsent(Campaign $campaign): array
    {
        $rows = DB::table('campaign_recipients as cr')->join('messages as m', 'm.id', '=', 'cr.message_id')
            ->where('cr.campaign_id', $campaign->id)
            ->groupBy('cr.consent_state')
            ->selectRaw("coalesce(cr.consent_state, 'unknown') AS state,
                count(*) FILTER (WHERE m.status IN ('accepted','sent','delivered','read')) AS sent,
                count(*) FILTER (WHERE m.status IN ('delivered','read')) AS delivered,
                count(*) FILTER (WHERE EXISTS (SELECT 1 FROM consent_events ce WHERE ce.contact_id = cr.contact_id
                    AND ce.action IN ('opted_out','marketing_opted_out') AND ce.created_at > m.created_at AND ce.created_at <= m.created_at + interval '72 hours')) AS blocked,
                count(*) FILTER (WHERE EXISTS (SELECT 1 FROM messages reply WHERE reply.conversation_id = m.conversation_id AND reply.direction = 'inbound'
                    AND reply.created_at > m.created_at AND reply.created_at <= m.created_at + interval '72 hours')) AS replied")
            ->get()->keyBy('state');

        $out = [];
        foreach (['opted_in', 'unknown', 'opted_out'] as $state) {
            $r = $rows->get($state);
            $out[$state] = ['sent' => (int) ($r->sent ?? 0), 'delivered' => (int) ($r->delivered ?? 0), 'blocked' => (int) ($r->blocked ?? 0), 'replied' => (int) ($r->replied ?? 0)];
        }

        return $out;
    }

    /** @return array{mm_api: int, cloud_api: int} */
    private function routes(Campaign $campaign): array
    {
        $rows = Message::query()->where('campaign_id', $campaign->id)->whereNotNull('send_route')
            ->selectRaw('send_route, count(*) AS total')->groupBy('send_route')->pluck('total', 'send_route');

        return [
            'mm_api' => (int) ($rows[MarketingMessages::ROUTE_MM] ?? 0),
            'cloud_api' => (int) ($rows[MarketingMessages::ROUTE_CLOUD] ?? 0) + (int) ($rows[MarketingMessages::ROUTE_MM_FALLBACK] ?? 0),
        ];
    }

    /** Recipients who wrote back within 72 hours of receiving the campaign message. */
    private function replied(Campaign $campaign): int
    {
        return Message::query()->where('campaign_id', $campaign->id)
            ->whereRaw("EXISTS (SELECT 1 FROM messages reply WHERE reply.conversation_id = messages.conversation_id AND reply.direction = 'inbound'
                AND reply.created_at > messages.created_at AND reply.created_at <= messages.created_at + interval '72 hours')")
            ->count();
    }

    /**
     * Why messages did not go out or were not delivered, most common first.
     *
     * @return list<array{reason: string, code: ?string, count: int, stage: string}>
     */
    public function failureReasons(Campaign $campaign): array
    {
        $before = CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereIn('status', ['skipped', 'failed'])
            ->selectRaw("coalesce(reason, 'Unknown') AS reason, status, count(*) AS total")->groupBy('reason', 'status')->toBase()->get()
            ->map(fn (object $r) => ['reason' => (string) $r->reason, 'code' => null, 'count' => (int) $r->total, 'stage' => $r->status === 'skipped' ? 'skipped' : 'not_sent']);
        $after = Message::query()->where('campaign_id', $campaign->id)->where('status', 'failed')
            ->selectRaw("coalesce(error_title, 'Rejected by WhatsApp') AS reason, error_code, count(*) AS total")->groupBy('error_title', 'error_code')->toBase()->get()
            ->map(fn (object $r) => ['reason' => (string) $r->reason, 'code' => $r->error_code !== null ? (string) $r->error_code : null, 'count' => (int) $r->total,
                'stage' => (string) $r->error_code === MetaErrorCatalog::PER_PERSON_MARKETING_LIMIT ? 'marketing_limit' : 'failed']);

        return $before->concat($after)->sortByDesc('count')->values()->all();
    }
}
