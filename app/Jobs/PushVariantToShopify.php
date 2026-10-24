<?php

namespace App\Jobs;

use App\Domain\Enums\ConflictStatus;
use App\Domain\Enums\SyncType;
use App\Domain\Enums\VariantSyncStatus;
use App\Exceptions\NonRetryableSyncException;
use App\Integrations\Shopify\ShopifyClient;
use App\Models\Variant;
use App\Services\SkuMapper;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Pousse le prix et la quantité vendable ERP d'un SKU vers Shopify.
 */
class PushVariantToShopify extends TrackedSyncJob
{
    public function __construct(public readonly string $sku) {}

    public static function type(): SyncType
    {
        return SyncType::PushVariant;
    }

    public static function fromPayload(array $payload): static
    {
        return new static((string) $payload['sku']);
    }

    public function payload(): array
    {
        return ['sku' => $this->sku];
    }

    public function subject(): ?string
    {
        return $this->sku;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Deux mises à jour du même SKU ne s'exécutent jamais en parallèle.
        return [(new WithoutOverlapping('push:'.$this->sku))->releaseAfter(5)->expireAfter(120)];
    }

    protected function execute(): array
    {
        $shopify = app(ShopifyClient::class);
        $mapper = app(SkuMapper::class);

        $variant = Variant::with(['stockLevels', 'product'])->where('sku', $this->sku)->first()
            ?? throw new NonRetryableSyncException("SKU {$this->sku} inconnu de l'ERP");

        if (! $variant->isMapped()) {
            $remote = $shopify->findVariantBySku($variant->sku);
            if ($remote === null) {
                $variant->update(['sync_status' => VariantSyncStatus::Unmapped]);

                throw new NonRetryableSyncException("SKU {$variant->sku} introuvable dans Shopify : créer la variante ou corriger le SKU");
            }
            $variant = $mapper->map($remote) ?? $variant;
            $variant->load('stockLevels', 'product');
        }

        $productId = (string) $variant->product->shopify_product_id;
        $available = $variant->availableQuantity();

        // Un conflit de prix ouvert attend une décision humaine : on ne pousse que le stock.
        $priceOnHold = $variant->conflicts()->where('field', 'price')->where('status', ConflictStatus::Open)->exists();

        try {
            if (! $priceOnHold) {
                $shopify->updateVariantPrice($productId, (string) $variant->shopify_variant_id, (string) $variant->price, $variant->compare_at_price);
            }
            $shopify->setAvailableQuantity((string) $variant->shopify_inventory_item_id, $available);
        } catch (\Throwable $e) {
            $variant->update(['sync_status' => VariantSyncStatus::Error]);

            throw $e;
        }

        $variant->update([
            'sync_status' => $priceOnHold ? VariantSyncStatus::Conflict : VariantSyncStatus::Synced,
            'last_synced_at' => now(),
        ]);

        return [
            'shopify_variant_id' => $variant->shopify_variant_id,
            'price' => $priceOnHold ? 'non poussé (conflit ouvert)' : (string) $variant->price,
            'available' => $available,
        ];
    }
}
