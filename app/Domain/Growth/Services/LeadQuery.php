<?php

declare(strict_types=1);

namespace App\Domain\Growth\Services;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Growth\GrowthSettings;
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
        'source' => ['nullable', 'in:meta,meta_paid,meta_post,meta_instagram,meta_facebook,google_ads,organic,direct,unknown,before'],
        'status' => ['nullable', 'in:ref,meta,multi,web,unattributed,before'],
        'phone_number_id' => ['nullable', 'uuid'],
        'assigned' => ['nullable', 'string', 'max:36'], // a membership id, "me" or "unassigned"
        'stage_id' => ['nullable', 'string', 'max:36'], // a stage id or "none"
        'days' => ['nullable', 'integer', 'in:7,30,90,365'],
        'tab' => ['nullable', 'in:all,new,unattributed'],
        'ad' => ['nullable', 'uuid'], // a growth_meta_ads id: leads whose original source is that ad
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
     * @param  array<string, mixed>  $f
     * @return Builder<Conversation>
     */
    public function filtered(array $f, bool $withTab = true): Builder
    {
        $settings = GrowthSettings::for($this->context->tenant());
        $membership = $this->context->membership();
        $query = $this->visible();

        $query
            ->where('last_message_at', '>=', now()->subDays((int) ($f['days'] ?? 30)))
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

        if (! empty($f['ad'])) {
            $sourceId = DB::table('growth_meta_ads')->where('id', $f['ad'])->value('source_id'); // RLS: own workspace only
            $query->whereHas('contact', fn ($c) => $sourceId === null ? $c->whereRaw('false') : $c->whereRaw("acquisition_original->>'ad_id' = ?", [$sourceId]));
        }

        if ($withTab) {
            $this->tab($query, (string) ($f['tab'] ?? 'all'));
        }

        return $query;
    }

    /** @param Builder<Conversation> $query */
    public function tab(Builder $query, string $tab): void
    {
        $startedAt = GrowthSettings::for($this->context->tenant())->startedAt;
        if ($tab === 'new') {
            // New = still in the first stage of the pipeline (or not staged yet).
            $first = CrmStage::query()->active()->orderBy('position')->value('id');
            $query->whereHas('contact', fn ($c) => $c->where(fn ($w) => $w->whereNull('stage_id')->when($first, fn ($w) => $w->orWhere('stage_id', $first))));
        }
        if ($tab === 'unattributed') {
            $query->whereHas('contact', fn ($c) => self::status($c, 'unattributed', $startedAt));
        }
    }

    /** @param Builder<Contact> $c */
    public static function status(Builder $c, string $status, ?Carbon $startedAt): void
    {
        match ($status) {
            'before' => $startedAt === null ? $c->whereRaw('false') : $c->where('contacts.created_at', '<', $startedAt),
            'meta' => self::tracked($c, $startedAt)->whereRaw("acquisition_original->>'source' = 'meta_ad'"),
            'unattributed' => self::tracked($c, $startedAt)->where(fn ($w) => $w->whereNull('acquisition_original')->orWhereRaw("coalesce(acquisition_original->>'source', '') <> 'meta_ad'")),
            // Reference matched, Multiple sources and Website · details unavailable come with website tracking.
            default => $c->whereRaw('false'),
        };
    }

    /** @param Builder<Contact> $c */
    public static function source(Builder $c, string $source, ?Carbon $startedAt): void
    {
        if (in_array($source, ['before', 'unknown', 'meta', 'meta_paid', 'meta_post', 'meta_instagram', 'meta_facebook'], true)) {
            self::status($c, ['before' => 'before', 'unknown' => 'unattributed'][$source] ?? 'meta', $startedAt);
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

        // Google Ads, Organic, Direct: website sources (arrive with website tracking).
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
