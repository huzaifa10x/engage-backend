<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\MetaMarkets;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loads Meta's WhatsApp rate card (the USD CSV from Meta's pricing page) for the date it applies
 * from. Earlier rate cards are kept: estimates already made keep the date of the rates they used.
 *
 *   php artisan engage:meta-rates:import storage/app/rates-2027-01.csv --effective=2027-01-01
 *
 * The CSV needs a header row with "Market" and the columns Marketing, Utility and Authentication
 * (Authentication-International is optional). Rows are matched by market name; "Other" is required.
 */
final class ImportMetaRates extends Command
{
    protected $signature = 'engage:meta-rates:import {file : Meta\'s rate card CSV (USD)} {--effective= : The date the rates apply from (YYYY-MM-DD)} {--dry-run}';

    protected $description = 'Load Meta\'s WhatsApp per-message rate card (USD) used for cost estimates';

    public function handle(): int
    {
        $effective = (string) $this->option('effective');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective) !== 1) {
            $this->error('Give the date the rates apply from with --effective=YYYY-MM-DD.');

            return self::FAILURE;
        }
        $path = (string) $this->argument('file');
        $handle = is_readable($path) ? fopen($path, 'r') : false;
        if ($handle === false) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $columns = null;
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            $cells = array_map(fn ($c) => trim((string) $c), $line);
            if ($columns === null) {
                $lower = array_map('strtolower', $cells);
                $find = fn (callable $test) => array_key_first(array_filter($lower, $test));
                $columns = [
                    'market' => $find(fn ($c) => str_contains($c, 'market') || $c === 'country'),
                    'currency' => $find(fn ($c) => str_contains($c, 'currency')),
                    'marketing' => $find(fn ($c) => str_starts_with($c, 'marketing')),
                    'utility' => $find(fn ($c) => str_starts_with($c, 'utility')),
                    'authentication' => $find(fn ($c) => str_starts_with($c, 'authentication') && ! str_contains($c, 'international')),
                    'authentication_international' => $find(fn ($c) => str_contains($c, 'international')),
                ];
                if (in_array(null, [$columns['market'], $columns['marketing'], $columns['utility'], $columns['authentication']], true)) {
                    $columns = null; // not the header yet (Meta's file may start with a title line)
                }

                continue;
            }
            $market = $cells[$columns['market']] ?? '';
            if ($market === '' || ! is_numeric(str_replace('$', '', $cells[$columns['marketing']] ?? ''))) {
                continue;
            }
            if ($columns['currency'] !== null && strtoupper($cells[$columns['currency']] ?? 'USD') !== 'USD') {
                fclose($handle);
                $this->error('This file is not in USD. Download the USD rate card from Meta.');

                return self::FAILURE;
            }
            $num = fn (?int $i) => $i === null || ! is_numeric(str_replace('$', '', $cells[$i] ?? '')) ? null : (float) str_replace('$', '', $cells[$i]);
            $rows[] = [
                'effective_from' => $effective, 'market' => $market, 'currency' => 'USD',
                'marketing' => $num($columns['marketing']), 'utility' => $num($columns['utility']), 'authentication' => $num($columns['authentication']),
                'authentication_international' => $num($columns['authentication_international']),
                'source' => mb_substr('Meta rate card CSV: '.basename($path), 0, 190), 'created_at' => now(),
            ];
        }
        fclose($handle);

        if ($rows === [] || ! in_array(MetaMarkets::OTHER, array_column($rows, 'market'), true)) {
            $this->error('No rate rows found, or the "Other" market is missing. Check the file is Meta\'s rate card.');

            return self::FAILURE;
        }
        $known = array_unique(array_merge([MetaMarkets::OTHER], array_map(fn ($c) => MetaMarkets::forCountry($c), ['AE', 'SA', 'IN', 'PK', 'GB', 'US'])));
        $missing = array_diff($known, array_column($rows, 'market'));
        if ($missing !== []) {
            $this->warn('Markets not in the file (priced as Other until added): '.implode(', ', $missing));
        }

        $this->table(['Market', 'Marketing', 'Utility', 'Authentication', 'Auth. international'],
            array_map(fn ($r) => [$r['market'], $r['marketing'], $r['utility'], $r['authentication'], $r['authentication_international'] ?? '-'], $rows));
        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing saved.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $effective) {
            DB::table('meta_rates')->where('effective_from', $effective)->delete();
            DB::table('meta_rates')->insert($rows);
        });
        $this->info(count($rows).' markets saved, applying from '.Carbon::parse($effective)->toFormattedDateString().'.');

        return self::SUCCESS;
    }
}
