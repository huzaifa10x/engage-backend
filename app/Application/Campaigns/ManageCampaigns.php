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
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Crm\Models\Segment;
use App\Domain\Crm\Services\SegmentQuery;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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

        /** @var ?MessageTemplate $template */
        $template = MessageTemplate::query()->where('waba_account_id', $number->waba_account_id)->find($data['template_id']);
        if ($template === null) {
            throw ValidationException::withMessages(['template_id' => 'Choose a template of this WhatsApp number.']);
        }
        if (! $template->isSendable()) {
            throw ValidationException::withMessages(['template_id' => 'Only approved templates can be sent in a campaign.']);
        }

        $definition = $template->variables();
        $variables = [
            'header' => array_values(array_map('strval', (array) ($data['variables']['header'] ?? []))),
            'body' => array_values(array_map('strval', (array) ($data['variables']['body'] ?? []))),
            'buttons' => array_map('strval', (array) ($data['variables']['buttons'] ?? [])),
        ];
        foreach (['header', 'body'] as $part) {
            $filled = array_filter($variables[$part], fn (string $v) => trim($v) !== '');
            if (count($filled) !== count($definition[$part]) || count($variables[$part]) !== count($definition[$part])) {
                throw ValidationException::withMessages(["variables.{$part}" => "Fill in every {$part} variable of the template (you can use contact fields such as {{first_name}})."]);
            }
        }
        foreach ($definition['buttons'] as $button) {
            if ($button['variable'] && $button['type'] === 'URL' && trim((string) ($variables['buttons'][(string) $button['index']] ?? '')) === '') {
                throw ValidationException::withMessages(['variables.buttons' => "Fill in the link value for the \"{$button['text']}\" button."]);
            }
        }

        $mediaId = null;
        $headerMedia = self::MEDIA_HEADERS[$definition['header_format'] ?? ''] ?? null;
        if ($headerMedia !== null) {
            $media = isset($data['media_id']) ? Media::query()->find($data['media_id']) : null;
            if ($media === null || Media::whatsappTypeFor((string) $media->mime_type) !== $headerMedia) {
                throw ValidationException::withMessages(['media_id' => "This template needs a header {$headerMedia}. Attach one."]);
            }
            $mediaId = $media->id;
        }

        $segment = isset($data['segment_id']) ? Segment::query()->findOrFail($data['segment_id']) : null;

        $campaign ??= new Campaign(['created_by_membership_id' => $membership?->id]);
        $campaign->fill([
            'name' => trim((string) $data['name']),
            'phone_number_id' => $number->id,
            'message_template_id' => $template->id,
            'template_name' => $template->name,
            'template_language' => $template->language,
            'template_category' => $template->category,
            'variables' => $variables,
            'media_id' => $mediaId,
            'segment_id' => $segment?->id,
            'audience_tag' => isset($data['audience_tag']) && trim((string) $data['audience_tag']) !== '' ? trim((string) $data['audience_tag']) : null,
            'audience_filter' => $this->segments->filter($data['filter'] ?? null),
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'objective' => $data['objective'] ?? null,
            'include_non_opted_in' => (bool) ($data['include_non_opted_in'] ?? false),
        ])->save();

        $this->audit->record($campaign->wasRecentlyCreated ? 'campaign.created' : 'campaign.updated', $campaign, after: ['name' => $campaign->name]);

        return $campaign;
    }

    /** Send now, or at $at (which requires the scheduling feature). */
    public function launch(Campaign $campaign, ?Carbon $at = null): Campaign
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

        $counts = $this->counts($audience, $campaign->template_category, (bool) $campaign->include_non_opted_in);
        if ($counts['eligible'] === 0) {
            throw CampaignException::cannot('Nobody in this audience can be messaged: '.($counts['matched'] === 0 ? 'no contact matches the segment.' : ($counts['not_opted_in'] > 0 ? 'none of the matching contacts has opted in. Tick “Include contacts who have not opted in” to reach them.' : 'all the matching contacts opted out or have no WhatsApp number.')));
        }
        // The plan's monthly reach is checked before the first message, never mid-campaign.
        $this->entitlements->ensureWithinLimit($tenant, FeatureKey::CampaignReachMonthly, $counts['eligible']);

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
        ])->save();

        $this->audit->record($scheduled ? 'campaign.scheduled' : 'campaign.launched', $campaign, meta: $counts + ['include_non_opted_in' => (bool) $campaign->include_non_opted_in]);

        if (! $scheduled) {
            LaunchCampaign::dispatch($campaign->id)->afterCommit();
        }

        return $campaign;
    }

    /** Stops a scheduled campaign, or a running one: messages already handed to WhatsApp still go out. */
    public function cancel(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Paused], true)) {
            throw CampaignException::cannot('Only a scheduled, sending or paused campaign can be stopped.');
        }

        $wasScheduled = $campaign->status === CampaignStatus::Scheduled;
        CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'pending')
            ->update(['status' => 'skipped', 'reason' => 'Campaign stopped', 'updated_at' => now()]);
        $campaign->forceFill($wasScheduled
            ? ['status' => CampaignStatus::Draft, 'scheduled_at' => null]
            : ['status' => CampaignStatus::Cancelled, 'completed_at' => now()])->save();
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
            $campaign->only(['phone_number_id', 'message_template_id', 'template_name', 'template_language', 'template_category', 'variables', 'media_id', 'segment_id', 'audience_tag', 'audience_filter', 'notes', 'objective', 'include_non_opted_in']),
            ['name' => mb_substr($campaign->name, 0, 112).' (copy)', 'status' => CampaignStatus::Draft, 'created_by_membership_id' => $this->context->membership()?->id],
        ));
        $this->audit->record('campaign.duplicated', $copy, meta: ['from' => $campaign->id]);

        return $copy;
    }

    /**
     * @param  array{match?: string, rules?: array<int, array<string, mixed>>, tag?: ?string, filter?: mixed}|null  $audience
     * @return array{matched: int, eligible: int, not_opted_in: int} not_opted_in: reachable marketing contacts without a recorded opt-in
     */
    public function counts(?array $audience, ?string $category, bool $includeNonOptedIn = false): array
    {
        $matched = $this->audienceQuery($audience)->count();
        $eligible = $this->eligibleQuery($audience, $category, $includeNonOptedIn)->count();
        $notOptedIn = strtoupper((string) $category) === 'MARKETING'
            ? $this->eligibleQuery($audience, $category, true)->where('consent_state', '!=', ConsentState::OptedIn->value)->count() : 0;

        return ['matched' => $matched, 'eligible' => $eligible, 'not_opted_in' => $notOptedIn];
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
    public function eligibleQuery(?array $audience, ?string $category, bool $includeNonOptedIn = false): Builder
    {
        $query = $this->audienceQuery($audience)
            ->where('consent_state', '!=', ConsentState::OptedOut->value)
            ->where(fn (Builder $w) => $w->whereNotNull('wa_id')->orWhereNotNull('bsuid'));

        if (strtoupper((string) $category) === 'MARKETING') {
            // Opted-in contacts only, unless the sender includes those who have not opted in (never the opted-out).
            $query->where('marketing_opted_out', false)
                ->when(! $includeNonOptedIn, fn (Builder $q) => $q->where('consent_state', ConsentState::OptedIn->value));

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
     * @return array<string, int|array<string, int>>
     */
    public function stats(Campaign $campaign): array
    {
        $recipients = CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');
        $messages = Message::query()->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');

        $m = fn (string ...$statuses) => (int) array_sum(array_map(fn (string $s) => (int) ($messages[$s] ?? 0), $statuses));

        return [
            'matched' => $campaign->matched_count,
            'eligible' => $campaign->eligible_count,
            'pending' => (int) ($recipients['pending'] ?? 0),
            'skipped' => (int) ($recipients['skipped'] ?? 0),
            'queued' => $m('queued'),
            'sent' => $m('accepted', 'sent', 'delivered', 'read'),
            'delivered' => $m('delivered', 'read'),
            'read' => $m('read'),
            'failed' => $m('failed') + (int) ($recipients['failed'] ?? 0),
            'replied' => $this->replied($campaign),
            // Which API delivered the campaign's messages (Marketing Messages API vs Cloud API).
            'routes' => $this->routes($campaign),
        ];
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
            ->map(fn (object $r) => ['reason' => (string) $r->reason, 'code' => $r->error_code !== null ? (string) $r->error_code : null, 'count' => (int) $r->total, 'stage' => 'failed']);

        return $before->concat($after)->sortByDesc('count')->values()->all();
    }
}
