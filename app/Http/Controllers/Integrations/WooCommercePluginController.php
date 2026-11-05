<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Commerce\Services\ChatLinks;
use App\Domain\Commerce\Services\OrderPayload;
use App\Domain\Commerce\Services\Orders;
use App\Domain\Integrations\Jobs\HandleStoreWebhook;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Integrations\Providers\WooCommercePlugin;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * What the "10X Engage for WooCommerce" plugin calls. Not signed in by a person: connect proves
 * itself with the one-time workspace key, everything after that with the store's signature.
 *
 *  POST woocommerce/plugin/connect          key + store address → store ID, signing secret, tracker
 *  POST woocommerce/{store}/plugin/events   order events (created, paid, completed, refunded,
 *                                           cancelled, failed, pending, updated), up to 50 at a time
 *  POST woocommerce/{store}/plugin/heartbeat  hourly: versions, queue, latest order → tracker, settings
 *  POST woocommerce/{store}/plugin/reconcile  nightly catch-up: the store's recent orders → the ones to resend
 */
final class WooCommercePluginController extends Controller
{
    private const MAX_EVENTS = 50;

    private const MAX_MANIFEST = 1000;

    public function __construct(private readonly TenantContext $context, private readonly WooCommercePlugin $plugin) {}

    /** The plugin itself, zipped for Plugins → Add New → Upload Plugin. Nothing secret is inside. */
    public function download(): BinaryFileResponse
    {
        $dir = resource_path('integrations/woocommerce');
        $slug = '10x-engage-for-woocommerce';
        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'xewc').'.zip';
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir.'/'.$slug, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $zip->addFile($file->getPathname(), $slug.'/'.ltrim(substr($file->getPathname(), strlen($dir.'/'.$slug)), '/'));
        }
        $zip->close();

        return response()->download($path, $slug.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:500'],
            'site_url' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:200'],
            'plugin_version' => ['nullable', 'string', 'max:20'],
            'wc_version' => ['nullable', 'string', 'max:20'],
            'wp_version' => ['nullable', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'max:3'],
            'hpos' => ['nullable', 'boolean'],
            'block_checkout' => ['nullable', 'boolean'],
        ]);
        try {
            ['integration' => $integration, 'secret' => $secret] = $this->plugin->connect($data['key'], $data['site_url'], $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['store_id' => $integration->public_id, 'secret' => $secret, 'name' => $integration->name] + $this->settings($integration)], 201);
    }

    public function events(Request $request, string $publicId, Orders $orders): JsonResponse
    {
        return $this->signed($request, $publicId, function (Integration $integration) use ($request, $orders) {
            $events = $request->json('events');
            if (! is_array($events) || count($events) > self::MAX_EVENTS) {
                return response()->json(['message' => 'Send between 1 and '.self::MAX_EVENTS.' events.'], 422);
            }
            $accepted = $duplicates = $rejected = 0;
            $results = [];
            foreach ($events as $e) {
                $order = is_array($e) && is_array($e['order'] ?? null) ? OrderPayload::parse($e['order']) : null;
                if ($order === null) {
                    $rejected++;

                    continue;
                }
                $event = (string) ($e['event'] ?? 'updated');
                $at = null;
                try {
                    $at = isset($e['occurred_at']) && is_string($e['occurred_at']) ? Carbon::parse($e['occurred_at'])->utc() : null;
                } catch (Throwable) {
                    $at = null;
                }
                $result = $orders->record($integration, $order, $event, $at, ($e['via'] ?? null) === 'catch_up' ? 'catch_up' : 'plugin');
                $result['duplicate'] ? $duplicates++ : $accepted++;
                $results[] = ['id' => $order->id, 'match' => $result['order']?->match, 'duplicate' => $result['duplicate']];
                if (! $result['duplicate']) {
                    // Store messages (order confirmed, shipped, cancelled) use the same events.
                    HandleStoreWebhook::dispatch($integration->id, $event === 'created' ? 'order.created' : 'order.updated', $e['order'], true);
                }
            }

            return response()->json(['data' => ['accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected, 'orders' => $results]]);
        });
    }

    public function heartbeat(Request $request, string $publicId): JsonResponse
    {
        return $this->signed($request, $publicId, function (Integration $integration) use ($request) {
            $settings = (array) $integration->settings;
            $settings['plugin'] = WooCommercePlugin::pluginMeta((array) $request->json()->all()) + ['heartbeat_at' => Carbon::now()->toIso8601String()] + (array) ($settings['plugin'] ?? []);
            $integration->forceFill(['settings' => $settings])->save();

            return response()->json(['data' => $this->settings($integration)]);
        });
    }

    public function reconcile(Request $request, string $publicId): JsonResponse
    {
        return $this->signed($request, $publicId, function (Integration $integration) use ($request) {
            $manifest = $request->json('orders');
            if (! is_array($manifest) || count($manifest) > self::MAX_MANIFEST) {
                return response()->json(['message' => 'Send up to '.self::MAX_MANIFEST.' orders.'], 422);
            }
            $theirs = [];
            foreach ($manifest as $row) {
                if (is_array($row) && is_scalar($row['id'] ?? null) && is_string($row['modified'] ?? null)) {
                    try {
                        $theirs[(string) $row['id']] = Carbon::parse($row['modified'])->utc();
                    } catch (Throwable) {
                        // skip unreadable rows
                    }
                }
            }
            $ours = CommerceOrder::query()->where('integration_id', $integration->id)->whereIn('external_id', array_keys($theirs))
                ->pluck('store_updated_at', 'external_id');
            $resend = [];
            foreach ($theirs as $id => $modified) {
                $known = $ours[$id] ?? null;
                if ($known === null || Carbon::parse($known)->lt($modified->copy()->startOfSecond())) {
                    $resend[] = (string) $id;
                }
            }
            $settings = (array) $integration->settings;
            $settings['plugin'] = ['reconciled_at' => Carbon::now()->toIso8601String(), 'reconcile_gaps' => count($resend)] + (array) ($settings['plugin'] ?? []);
            $integration->forceFill(['settings' => $settings])->save();

            return response()->json(['data' => ['resend' => $resend]]);
        });
    }

    /**
     * What the plugin keeps in sync: the tracker to print, the link parameter and how long it is valid.
     *
     * @return array{tracker_src: ?string, link_param: string, link_days: int, status: string}
     */
    private function settings(Integration $integration): array
    {
        $tenant = $this->context->bypass(fn () => Tenant::query()->find($integration->tenant_id));
        $tracker = $tenant === null ? null : $this->context->run($tenant, fn () => $this->plugin->trackerSrc($integration));

        return [
            'tracker_src' => $tracker,
            'link_param' => ChatLinks::PARAM,
            'link_days' => (int) $integration->setting('link_days', ChatLinks::DEFAULT_DAYS),
            'status' => $integration->status,
        ];
    }

    /** @param callable(Integration): JsonResponse $then */
    private function signed(Request $request, string $publicId, callable $then): JsonResponse
    {
        /** @var ?Integration $integration */
        $integration = $this->context->bypass(fn () => Integration::query()->where('provider', Integration::WOOCOMMERCE)->where('public_id', $publicId)->first());
        if ($integration === null || ! $integration->viaPlugin()) {
            return response()->json(['message' => 'This store is not connected to 10X Engage. Connect it again with a new key.'], 410);
        }
        if (! $this->plugin->verify($integration, $request)) {
            return response()->json(['message' => 'Invalid or expired signature.'], 401);
        }
        $tenant = $this->context->bypass(fn () => Tenant::query()->find($integration->tenant_id));
        if ($tenant === null) {
            return response()->json(['message' => 'Workspace not found.'], 410);
        }

        return $this->context->run($tenant, fn () => $then($integration));
    }
}
