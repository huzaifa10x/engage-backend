<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Messaging\Models\Contact;
use App\Support\Phone\PhoneNumbers;
use Illuminate\Database\Eloquent\Builder;

/**
 * What Meta will charge for sending one template to a set of contacts, before it is sent:
 * recipients per country × Meta's rate for that country's market and the template's category.
 *
 *  - Paid by the client directly to Meta, in USD. Shown as an estimate: Meta's volume discounts on
 *    utility and authentication messages, and its currency conversion, are not applied.
 *  - Contacts that would not be sent to are left out: opted out (counted by the caller), and numbers
 *    that are not valid in their country.
 *  - Utility templates are free inside the 24-hour customer service window, so those recipients
 *    cost nothing. Marketing and authentication are always charged.
 *  - A contact Meta knows only by its business-scoped user ID (the phone number is hidden) is
 *    priced at the "Other" rate: the country is not known.
 */
final class CostEstimator
{
    public const LABEL = 'Estimate. Paid by you directly to Meta.';

    public function __construct(private readonly MetaRates $rates) {}

    /**
     * @param  Builder<Contact>  $eligible  the contacts that would receive the template
     * @return array<string, mixed>
     */
    public function estimate(Builder $eligible, ?string $category, ?string $phoneNumberId = null): array
    {
        $category = strtoupper((string) $category) ?: 'MARKETING';
        $card = $this->rates->card();
        $free = $category === 'UTILITY';

        $byCountry = [];
        $invalid = 0;
        $inWindow = 0;
        (clone $eligible)->select(['contacts.id', 'contacts.wa_id', 'contacts.bsuid'])
            ->when($free, fn (Builder $q) => $q->selectRaw('EXISTS (SELECT 1 FROM conversations cv WHERE cv.contact_id = contacts.id AND cv.window_expires_at > now()'
                .($phoneNumberId !== null ? ' AND cv.phone_number_id = ?' : '').') AS window_open', $phoneNumberId !== null ? [$phoneNumberId] : []))
            ->orderBy('contacts.id')
            ->chunk(2000, function ($contacts) use (&$byCountry, &$invalid, &$inWindow, $free) {
                foreach ($contacts as $c) {
                    $country = null;
                    if ($c->wa_id !== null) {
                        $country = PhoneNumbers::region((string) $c->wa_id);
                        if ($country === null) {
                            $invalid++;

                            continue;
                        }
                    }
                    if ($free && (bool) $c->getAttribute('window_open')) {
                        $inWindow++;

                        continue;
                    }
                    $byCountry[$country ?? '??'] = ($byCountry[$country ?? '??'] ?? 0) + 1;
                }
            });

        $rows = [];
        $total = 0.0;
        foreach ($byCountry as $country => $count) {
            $country = (string) $country;
            $market = $this->rates->marketFor($country === '??' ? null : $country);
            $rate = $this->rates->rate($market, $category) ?? 0.0;
            $cost = round($count * $rate, 4);
            $total += $cost;
            $rows[] = ['country' => $country === '??' ? null : $country, 'market' => $market, 'recipients' => $count, 'rate' => $rate, 'cost' => $cost];
        }
        usort($rows, fn ($a, $b) => $b['cost'] <=> $a['cost'] ?: $b['recipients'] <=> $a['recipients']);

        return [
            'label' => self::LABEL,
            'currency' => $card['currency'],
            'rates_effective_from' => $card['effective_from'],
            'category' => $category,
            'recipients' => array_sum($byCountry),
            'free_in_window' => $inWindow,
            'excluded_invalid' => $invalid,
            'total' => round($total, 2),
            'by_country' => $rows,
            'computed_at' => now()->toIso8601String(),
        ];
    }
}
