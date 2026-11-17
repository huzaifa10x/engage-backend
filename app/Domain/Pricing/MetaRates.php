<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Meta's per-message rate card (USD), from the meta_rates table: the rates in force on a day are the
 * rows of the latest effective_from on or before it. Updated with `engage:meta-rates:import`.
 */
final class MetaRates
{
    public const CATEGORIES = ['MARKETING', 'UTILITY', 'AUTHENTICATION'];

    /** @var array<string, array{effective_from: string, rates: Collection<string, object>}> */
    private array $memo = [];

    /** @return array{effective_from: ?string, currency: string, rates: Collection<string, object>} market → row */
    public function card(?Carbon $on = null): array
    {
        $day = ($on ?? now())->toDateString();
        if (! isset($this->memo[$day])) {
            $from = DB::table('meta_rates')->where('effective_from', '<=', $day)->max('effective_from');
            $rows = $from !== null ? DB::table('meta_rates')->where('effective_from', $from)->get()->keyBy('market') : collect();
            $this->memo[$day] = ['effective_from' => $from !== null ? Carbon::parse((string) $from)->toDateString() : '', 'rates' => $rows];
        }

        return ['effective_from' => $this->memo[$day]['effective_from'] ?: null, 'currency' => 'USD', 'rates' => $this->memo[$day]['rates']];
    }

    /** The market a country is priced in: its own or regional market, or "Other" when the rate card has no row for it. */
    public function marketFor(?string $country, ?Carbon $on = null): string
    {
        $market = $country === null ? MetaMarkets::OTHER : MetaMarkets::forCountry($country);

        return $this->card($on)['rates']->has($market) ? $market : MetaMarkets::OTHER;
    }

    /** USD per message for a template category in a market (null when there is no rate card). */
    public function rate(string $market, ?string $category, ?Carbon $on = null): ?float
    {
        $rates = $this->card($on)['rates'];
        $row = $rates->get($market) ?? $rates->get(MetaMarkets::OTHER);
        if ($row === null) {
            return null;
        }

        return match (strtoupper((string) $category)) {
            'UTILITY' => (float) $row->utility,
            'AUTHENTICATION' => (float) $row->authentication,
            default => (float) $row->marketing,
        };
    }

    /**
     * The rate of one category in every market, for the template page.
     *
     * @return list<array{market: string, rate: float, countries: list<string>}>
     */
    public function byMarket(?string $category): array
    {
        return $this->card()['rates']->values()
            ->map(fn (object $row) => ['market' => (string) $row->market, 'rate' => (float) $this->rate((string) $row->market, $category), 'countries' => MetaMarkets::countries((string) $row->market)])
            ->sortBy('market')->values()->all();
    }
}
