<?php

namespace App\Services;

use App\Domain\Enums\ConflictResolution;
use App\Domain\Enums\ConflictStatus;
use App\Domain\Enums\VariantSyncStatus;
use App\Integrations\Erp\ErpClient;
use App\Jobs\PushVariantToShopify;
use App\Models\Conflict;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ConflictResolver
{
    public function __construct(
        private readonly ErpClient $erp,
        private readonly SyncDispatcher $dispatcher,
    ) {}

    public function resolve(Conflict $conflict, ConflictResolution $resolution, string $resolvedBy): Conflict
    {
        if ($conflict->status !== ConflictStatus::Open) {
            throw new InvalidArgumentException('Ce conflit est déjà résolu.');
        }

        $variant = $conflict->variant;

        DB::transaction(function () use ($conflict, $resolution, $resolvedBy, $variant) {
            if ($resolution === ConflictResolution::AcceptShopify) {
                $this->erp->updatePrice($variant->sku, (string) $conflict->shopify_value);
                $variant->update(['sync_status' => VariantSyncStatus::Synced, 'last_synced_at' => now()]);
            } else {
                $variant->update(['sync_status' => VariantSyncStatus::Pending]);
            }

            $conflict->update([
                'status' => ConflictStatus::Resolved,
                'resolution' => $resolution,
                'resolved_by' => $resolvedBy,
                'resolved_at' => now(),
            ]);
        });

        // Garder l'ERP : on repousse le prix de référence vers Shopify.
        if ($resolution === ConflictResolution::KeepErp) {
            $this->dispatcher->dispatch(new PushVariantToShopify($variant->sku));
        }

        return $conflict->refresh();
    }
}
