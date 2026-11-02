<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Growth Hub Sprint 0 (check 5): export the Click-to-WhatsApp referral objects Meta already sent us,
 * with personal data removed, so they can be committed as test fixtures. Only the referral object
 * is exported — never phone numbers, names or message text. The ad click ID and media URLs are
 * replaced; ad IDs, source type, source URL, headline and body are kept (they describe the ad).
 */
final class ExportMetaReferrals extends Command
{
    protected $signature = 'engage:growth:export-referrals
        {--since= : Only messages received on or after this date (YYYY-MM-DD)}
        {--limit=200 : Maximum number of referrals}
        {--out= : Write to this file instead of the screen}';

    protected $description = 'Export anonymised Meta ad referral objects from inbound messages (Growth Hub Sprint 0).';

    public function handle(TenantContext $context): int
    {
        $rows = $context->bypass(fn () => DB::table('messages')
            ->where('direction', 'inbound')
            ->whereRaw("content ? 'referral'")
            ->whereRaw("jsonb_typeof(content->'referral') = 'object'")
            ->when($this->option('since'), fn ($q, $since) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['created_at', 'type', DB::raw("content->'referral' AS referral")]));

        $out = $rows->map(function ($row) {
            $referral = (array) json_decode((string) $row->referral, true);
            foreach (['ctwa_clid', 'image_url', 'video_url', 'thumbnail_url'] as $key) {
                if (isset($referral[$key])) {
                    $referral[$key] = $key === 'ctwa_clid' ? 'REDACTED-'.strlen((string) $referral[$key]).'-chars' : 'https://redacted.invalid/media';
                }
            }
            ksort($referral);

            return [
                'received_at' => (string) $row->created_at,
                'message_type' => $row->type,
                'fields_present' => array_keys($referral),
                'referral' => $referral,
            ];
        })->values()->all();

        $json = (string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($path = $this->option('out')) {
            file_put_contents((string) $path, $json.PHP_EOL);
            $this->components->info(count($out)." referral(s) written to {$path}.");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
