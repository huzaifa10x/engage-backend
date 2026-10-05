<?php

declare(strict_types=1);

namespace App\Application\Campaigns;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Audit\AuditLogger;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Exceptions\CampaignException;
use App\Domain\Campaigns\Jobs\LaunchCampaign;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
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
        $audience = ['match' => $segment->match ?? 'all', 'rules' => $segment->rules ?? [], 'name' => $segment?->name];

        $counts = $this->counts($audience, $campaign->template_category);
        if ($counts['eligible'] === 0) {
            throw CampaignException::cannot('Nobody in this audience can be messaged: '.($counts['matched'] === 0 ? 'no contact matches the segment.' : 'none of the matching contacts has given the required consent.'));
        }
        // The plan's monthly reach is checked before the first message, never mid-campaign.
        $this->entitlements->ensureWithinLimit($tenant, FeatureKey::CampaignReachMonthly, $counts['eligible']);

        $scheduled = $at !== null && $at->isFuture();
        if ($scheduled) {
            $this->entitlements->ensureEnabled($tenant, FeatureKey::CampaignScheduling);
        }

        $campaign->forceFill([
            'audience' => $audience,
            'matched_count' => $counts['matched'],
            'eligible_count' => $counts['eligible'],
            'status' => $scheduled ? CampaignStatus::Scheduled : CampaignStatus::Sending,
            'scheduled_at' => $scheduled ? $at : null,
            'started_at' => $scheduled ? null : now(),
            'failure_reason' => null,
        ])->save();

        $this->audit->record($scheduled ? 'campaign.scheduled' : 'campaign.launched', $campaign, meta: $counts);

        if (! $scheduled) {
            LaunchCampaign::dispatch($campaign->id)->afterCommit();
        }

        return $campaign;
    }

    /** Stops a scheduled campaign, or a running one: messages already handed to WhatsApp still go out. */
    public function cancel(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Sending], true)) {
            throw CampaignException::cannot('Only a scheduled or sending campaign can be stopped.');
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
        if (in_array($campaign->status, [CampaignStatus::Sending, CampaignStatus::Scheduled], true)) {
            throw CampaignException::cannot('Stop the campaign before deleting it.');
        }
        $this->audit->record('campaign.deleted', $campaign, meta: ['name' => $campaign->name]);
        $campaign->delete();
    }

    /**
     * @param  array{match?: string, rules?: array<int, array<string, mixed>>}|null  $audience
     * @return array{matched: int, eligible: int}
     */
    public function counts(?array $audience, ?string $category): array
    {
        $matched = $this->audienceQuery($audience)->count();
        $eligible = $this->eligibleQuery($audience, $category)->count();

        return ['matched' => $matched, 'eligible' => $eligible];
    }

    /**
     * @param  array{match?: string, rules?: array<int, array<string, mixed>>}|null  $audience
     * @return Builder<Contact>
     */
    public function audienceQuery(?array $audience): Builder
    {
        return $this->segments->apply(Contact::query(), (string) ($audience['match'] ?? 'all'), (array) ($audience['rules'] ?? []));
    }

    /**
     * The SQL twin of Contact::campaignBlocker(), for counting before launch.
     *
     * @param  array{match?: string, rules?: array<int, array<string, mixed>>}|null  $audience
     * @return Builder<Contact>
     */
    public function eligibleQuery(?array $audience, ?string $category): Builder
    {
        $query = $this->audienceQuery($audience)
            ->where('consent_state', '!=', ConsentState::OptedOut->value)
            ->where(fn (Builder $w) => $w->whereNotNull('wa_id')->orWhereNotNull('bsuid'));

        if (strtoupper((string) $category) === 'MARKETING') {
            $query->where('consent_state', ConsentState::OptedIn->value)->where('marketing_opted_out', false);
        }

        return $query;
    }

    /**
     * Pipeline numbers for one campaign: what happened before WhatsApp (recipients) and after
     * (message delivery statuses, cumulative: a read message was also delivered and sent).
     *
     * @return array<string, int>
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
        ];
    }
}
