<?php

declare(strict_types=1);

namespace App\Domain\Growth\Services;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Marketing\MarketingCohort;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Growth Hub › Leads reads the CRM: one row per WhatsApp conversation (contact + number), joined
 * read-only to the contact's acquisition projection. Never a second lead table (Developer Handover
 * §II.2). Who sees what (Blueprint v1.4 §IV): growth.view (Owner, Admin) sees every lead on the numbers
 * they may use; everyone else sees only the leads assigned to them.
 *
 * Statuses and sources are read from the projection exactly as Acquisition::present shows them, so a
 * filter never disagrees with the chip.
 */
final class LeadQuery
{
    /** Filters for the list, counts and export. */
    public const RULES = [
        'q' => ['nullable', 'string', 'max:100'],
        'source' => ['nullable', 'in:meta,meta_paid,meta_post,meta_instagram,meta_facebook,google_ads,paid_other,website,organic,direct,unknown,before'],
        'status' => ['nullable', 'in:ref,meta,multi,web,unattributed,before'],
        'phone_number_id' => ['nullable', 'uuid'],
        'assigned' => ['nullable', 'string', 'max:36'], // a membership id, "me" or "unassigned"
        'stage_id' => ['nullable', 'string', 'max:36'], // a stage id or "none"
        'days' => ['nullable', 'integer', 'in:7,30,90,365'],
        // new: their first-ever message came from a paid tracked source; returning: they existed before
        // (or first wrote without a source) and came back through an ad or the website.
        'tab' => ['nullable', 'in:all,new,returning'],
        'ad' => ['nullable', 'uuid'], // a growth_meta_ads id: leads whose original source is that ad
        // Drill-down from Analytics → Marketing: the new-lead cohort of a period (local dates), with
        // its source bucket, campaign and funnel step, counted exactly as the report counts it.
        'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
        'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        'bucket' => ['nullable', 'string', 'max:40'],
        'campaign' => ['nullable', 'string', 'max:120'],
        'step' => ['nullable', 'in:contacted,qualified'],
    ];

    public function __construct(private readonly TenantContext $context, private readonly NumberAccess $access) {}

    public function seesAll(): bool
    {
        return $this->context->can(Permission::GrowthView);
    }

    /** @return Builder<Conversation> the leads this member may see, before any filter */
    public function visible(): Builder
    {
        $membership = $this->context->membership() ?? abort(403);
        $query = Conversation::query()->whereNotNull('last_message_at')->whereHas('contact');
        $this->access->scope($query, $membership, 'phone_number_id');

        return $this->seesAll() ? $query : $query->where('assigned_membership_id', $membership->id);
    }

    /**
     * Growth Hub › Leads lists paid-advertising leads only (a Click-to-WhatsApp ad or boosted post
     * brought them: first message or a later touch). Ordinary chats stay in Chat and Contacts.
     *
     * @param  Builder<Contact>  $c
     */
    public static function paidLead(Builder $c): void
    {
        $paid = "'".implode("','", Acquisition::PAID_SOURCES)."'";
        $c->where(fn ($w) => $w->whereRaw("acquisition_original->>'source' IN ({$paid})")->orWhereRaw("acquisition_latest->>'source' IN ({$paid})"));
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<Conversation>
     */
    public function filtered(array $f, bool $withTab = true): Builder
    {
        $settings = GrowthSettings::for($this->context->tenant());
        $membership = $this->context->membership();
        $query = $this->visible();

        $query
            // Leads with a paid touch in the period (the Marketing drill-down uses its own cohort instead).
            ->when(empty($f['from']), fn ($q) => $q->whereHas('contact', function ($c) use ($f) {
                self::paidLead($c);
                $c->whereRaw("coalesce((acquisition_latest->>'occurred_at')::timestamptz, (acquisition_original->>'occurred_at')::timestamptz) >= ?", [now()->subDays((int) ($f['days'] ?? 30))]);
            }))
            ->when($f['phone_number_id'] ?? null, fn ($q, string $id) => $q->where('phone_number_id', $id))
            ->when($f['assigned'] ?? null, fn ($q, string $a) => match ($a) {
                'unassigned' => $q->whereNull('assigned_membership_id'),
                'me' => $q->where('assigned_membership_id', $membership?->id),
                default => $q->where('assigned_membership_id', $a),
            })
            ->when($f['q'] ?? null, function ($q, string $term) {
                $digits = preg_replace('/\D+/', '', $term);
                $q->whereHas('contact', fn ($c) => $c->where(fn ($w) => $w
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('profile_name', 'ilike', "%{$term}%")
                    ->when($digits !== '', fn ($w) => $w->orWhere('wa_id', 'like', "%{$digits}%"))));
            })
            ->when($f['stage_id'] ?? null, fn ($q, string $id) => $q->whereHas('contact', fn ($c) => $id === 'none' ? $c->whereNull('stage_id') : $c->where('stage_id', $id)))
            ->when($f['status'] ?? null, fn ($q, string $s) => $q->whereHas('contact', fn ($c) => self::status($c, $s, $settings->startedAt)))
            ->when($f['source'] ?? null, fn ($q, string $s) => $q->whereHas('contact', fn ($c) => self::source($c, $s, $settings->startedAt)));

        if (! empty($f['from']) && ! empty($f['to'])) {
            $this->cohort($query, $f);
        }

        if (! empty($f['ad'])) {
            $sourceId = DB::table('growth_meta_ads')->where('id', $f['ad'])->value('source_id'); // RLS: own workspace only
            $query->whereHas('contact', fn ($c) => $sourceId === null ? $c->whereRaw('false') : $c->whereRaw("acquisition_original->>'ad_id' = ?", [$sourceId]));
        }

        if ($withTab) {
            $this->tab($query, (string) ($f['tab'] ?? 'all'));
        }

        return self::perContact($query);
    }

    /**
     * One row per person: a contact with several conversations (two numbers, or a chat on each) is
     * listed once, on its most recently active conversation among those that match. Repeat taps and
     * other conversations belong in that contact's Journey, and every count counts people.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public static function perContact(Builder $query): Builder
    {
        $latest = (clone $query)->reorder()->select([])
            ->selectRaw('DISTINCT ON (conversations.contact_id) conversations.id')
            ->orderBy('conversations.contact_id')->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id');

        return Conversation::query()->whereIn('conversations.id', $latest->toBase());
    }

    /**
     * Only the leads the Marketing report counted: one conversation per lead, on the number its first
     * message came in on, so the list has exactly as many rows as the number clicked.
     *
     * @param  Builder<Conversation>  $query
     * @param  array<string, mixed>  $f
     */
    private function cohort(Builder $query, array $f): void
    {
        $tenant = $this->context->tenant();
        [$sql, $bindings] = MarketingCohort::sql($tenant, MarketingCohort::mapping($tenant), (string) $f['from'], (string) $f['to']);
        $where = [];
        $extra = [];
        if (! empty($f['bucket'])) {
            if ($f['bucket'] === 'meta') {
                $where[] = "x.bucket IN ('meta_instagram', 'meta_facebook', 'meta_unsplit')";
            } else {
                $where[] = 'x.bucket = ?';
                $extra[] = $f['bucket'];
            }
        }
        if (! empty($f['campaign'])) {
            $where[] = 'x.campaign_key = ?';
            $extra[] = $f['campaign'];
        }
        if (($f['step'] ?? null) === 'contacted') {
            $where[] = 'x.contacted';
        } elseif (($f['step'] ?? null) === 'qualified') {
            $where[] = 'x.qualified';
        }
        $filter = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);

        $query->whereRaw(
            "(conversations.contact_id, conversations.phone_number_id::text) IN (SELECT x.contact_id, x.number_id FROM ({$sql}) x{$filter})",
            [...$bindings, ...$extra],
        );
    }

    /** @param Builder<Conversation> $query */
    public function tab(Builder $query, string $tab): void
    {
        $startedAt = GrowthSettings::for($this->context->tenant())->startedAt;
        if ($tab === 'new') {
            $query->whereHas('contact', fn ($c) => self::newLead($c, $startedAt));
        }
        if ($tab === 'returning') {
            $query->whereHas('contact', fn ($c) => $c->whereNot(fn ($w) => self::newLead($w, $startedAt)));
        }
    }

    /**
     * A new lead: created after tracking started and its first-ever message came from a paid
     * tracked source (the original source never changes).
     *
     * @param  Builder<Contact>  $c
     */
    public static function newLead(Builder $c, ?Carbon $startedAt): void
    {
        $paid = "'".implode("','", Acquisition::PAID_SOURCES)."'";
        self::tracked($c, $startedAt)->whereRaw("coalesce(acquisition_original->>'source', '') IN ({$paid})");
    }

    /** @param Builder<Contact> $c */
    public static function status(Builder $c, string $status, ?Carbon $startedAt): void
    {
        match ($status) {
            'before' => $startedAt === null ? $c->whereRaw('false') : $c->where('contacts.created_at', '<', $startedAt),
            'meta' => self::tracked($c, $startedAt)->whereRaw("acquisition_original->>'source' = 'meta_ad' AND coalesce(acquisition_original->>'type', 'meta_ctwa') = 'meta_ctwa' AND coalesce((acquisition_original->>'conflict')::boolean, false) = false"),
            'multi' => self::tracked($c, $startedAt)->whereRaw("coalesce((acquisition_original->>'conflict')::boolean, false) = true"),
            'ref' => self::tracked($c, $startedAt)->whereRaw("acquisition_original->>'type' = 'website_reference'"),
            'web' => self::tracked($c, $startedAt)->whereRaw("acquisition_original->>'type' = 'website_unregistered'"),
            'unattributed' => self::tracked($c, $startedAt)->whereNull('acquisition_original'),
            default => $c->whereRaw('false'),
        };
    }

    /** @param Builder<Contact> $c */
    public static function source(Builder $c, string $source, ?Carbon $startedAt): void
    {
        if ($source === 'before' || $source === 'unknown') {
            self::status($c, $source === 'before' ? 'before' : 'unattributed', $startedAt);

            return;
        }
        if ($source === 'website') {
            // Any lead whose first message came from a tracked website tap.
            self::tracked($c, $startedAt)->whereRaw("acquisition_original->>'type' IN ('website_reference', 'website_unregistered')");

            return;
        }
        if (in_array($source, ['meta', 'meta_paid', 'meta_post', 'meta_instagram', 'meta_facebook'], true)) {
            // Meta: a Click-to-WhatsApp ad, or a website tap from a Meta ad (utm_source=ig / fb).
            self::tracked($c, $startedAt)->whereRaw("acquisition_original->>'source' = 'meta_ad'");
            // Paid ads vs boosted posts (referral source_type; older touches without it count as ads).
            if ($source === 'meta_paid' || $source === 'meta_post') {
                $c->whereRaw("coalesce(acquisition_original->>'source_type', 'ad') ".($source === 'meta_post' ? '=' : '<>')." 'post'");
            }
            // "Meta ad · Instagram / Facebook" only with stored proof (Blueprint v1.4).
            if ($source === 'meta_instagram' || $source === 'meta_facebook') {
                $c->whereRaw("acquisition_original->>'platform' = ?", [$source === 'meta_instagram' ? 'instagram' : 'facebook']);
            }

            return;
        }

        // Google Ads, other paid campaigns, Organic, Direct: website sources.
        self::tracked($c, $startedAt)->whereRaw("acquisition_original->>'source' = ?", [$source]);
    }

    /**
     * Created after tracking started (the only contacts that can be new leads).
     *
     * @param  Builder<Contact>  $c
     * @return Builder<Contact>
     */
    public static function tracked(Builder $c, ?Carbon $startedAt): Builder
    {
        return $startedAt === null ? $c : $c->where('contacts.created_at', '>=', $startedAt);
    }
}
