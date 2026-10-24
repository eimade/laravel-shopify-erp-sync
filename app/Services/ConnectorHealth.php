<?php

namespace App\Services;

use App\Domain\Enums\ConflictStatus;
use App\Domain\Enums\SyncStatus;
use App\Integrations\Shopify\ShopifyClient;
use App\Models\Conflict;
use App\Models\SyncRun;
use App\Models\Variant;
use App\Models\WebhookReceipt;
use Throwable;

/**
 * Indicateurs de santé des connecteurs, affichés dans Filament et exposés en GraphQL.
 */
class ConnectorHealth
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * @return array{
     *     shopify_ok: bool, shopify_driver: string, shop_name: ?string, shopify_error: ?string, latency_ms: int,
     *     last_success_at: ?string, runs_24h: int, failed_24h: int, dead_letters: int, success_rate_24h: float,
     *     open_conflicts: int, unmapped_variants: int, last_webhook_at: ?string
     * }
     */
    public function snapshot(): array
    {
        $start = hrtime(true);
        $shopName = null;
        $error = null;
        try {
            $shopName = $this->shopify->shopName();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $latency = (int) round((hrtime(true) - $start) / 1_000_000);

        $since = now()->subDay();
        $runs24h = SyncRun::where('created_at', '>=', $since)->count();
        $failed24h = SyncRun::where('created_at', '>=', $since)
            ->whereIn('status', [SyncStatus::Failed, SyncStatus::Dead])->count();

        return [
            'shopify_ok' => $error === null,
            'shopify_driver' => (string) config('synchub.shopify.driver'),
            'shop_name' => $shopName,
            'shopify_error' => $error,
            'latency_ms' => $latency,
            'last_success_at' => SyncRun::where('status', SyncStatus::Succeeded)->max('finished_at'),
            'runs_24h' => $runs24h,
            'failed_24h' => $failed24h,
            'dead_letters' => SyncRun::where('status', SyncStatus::Dead)->count(),
            'success_rate_24h' => $runs24h === 0 ? 100.0 : round(100 * ($runs24h - $failed24h) / $runs24h, 1),
            'open_conflicts' => Conflict::where('status', ConflictStatus::Open)->count(),
            'unmapped_variants' => Variant::whereNull('shopify_variant_id')->count(),
            'last_webhook_at' => WebhookReceipt::max('created_at'),
        ];
    }
}
