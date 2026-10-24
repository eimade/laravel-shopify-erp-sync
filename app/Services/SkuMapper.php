<?php

namespace App\Services;

use App\Domain\Enums\ConflictStatus;
use App\Domain\Enums\VariantSyncStatus;
use App\Integrations\Shopify\Data\ShopifyVariant;
use App\Models\Conflict;
use App\Models\Variant;

/**
 * Rapprochement SKU ERP <-> variante Shopify et détection des écarts de prix.
 */
class SkuMapper
{
    /** Normalisation commune aux deux systèmes : espaces retirés, majuscules. */
    public static function normalize(?string $sku): ?string
    {
        if ($sku === null) {
            return null;
        }
        $sku = strtoupper((string) preg_replace('/\s+/', '', $sku));

        return $sku === '' ? null : $sku;
    }

    public static function pricesDiffer(string|float|null $a, string|float|null $b): bool
    {
        return (int) round(((float) $a) * 100) !== (int) round(((float) $b) * 100);
    }

    /**
     * Enregistre les identifiants Shopify sur la variante ERP correspondante.
     * Renvoie null si le SKU est inconnu de l'ERP.
     */
    public function map(ShopifyVariant $remote): ?Variant
    {
        $sku = self::normalize($remote->sku);
        if ($sku === null) {
            return null;
        }

        $variant = Variant::with('product')->where('sku', $sku)->first();
        if ($variant === null) {
            return null;
        }

        // Un variant Shopify ne peut être rattaché qu'à un seul SKU ERP.
        Variant::where('shopify_variant_id', $remote->id)->whereKeyNot($variant->id)
            ->update(['shopify_variant_id' => null, 'shopify_inventory_item_id' => null, 'sync_status' => VariantSyncStatus::Unmapped]);

        $variant->fill([
            'shopify_variant_id' => $remote->id,
            'shopify_inventory_item_id' => $remote->inventoryItemId,
        ]);
        if ($variant->sync_status === VariantSyncStatus::Unmapped) {
            $variant->sync_status = VariantSyncStatus::Pending;
        }
        $variant->save();

        if ($variant->product->shopify_product_id !== $remote->productId) {
            $variant->product->update(['shopify_product_id' => $remote->productId]);
        }

        return $variant;
    }

    /**
     * Ouvre un conflit si le prix Shopify diverge du prix ERP (un seul conflit ouvert par champ).
     */
    public function detectPriceConflict(Variant $variant, string $shopifyPrice, string $source): ?Conflict
    {
        if (! self::pricesDiffer($variant->price, $shopifyPrice)) {
            return null;
        }

        $conflict = Conflict::updateOrCreate(
            ['variant_id' => $variant->id, 'field' => 'price', 'status' => ConflictStatus::Open],
            ['erp_value' => (string) $variant->price, 'shopify_value' => $shopifyPrice, 'source' => $source],
        );
        $variant->update(['sync_status' => VariantSyncStatus::Conflict]);

        return $conflict;
    }
}
