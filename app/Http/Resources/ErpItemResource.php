<?php

namespace App\Http\Resources;

use App\Models\StockLevel;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Variant
 */
class ErpItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Variant $variant */
        $variant = $this->resource;

        return [
            'sku' => $variant->sku,
            'product' => [
                'handle' => $variant->product->handle,
                'title' => $variant->product->title,
            ],
            'variant_title' => $variant->title,
            'price' => (string) $variant->price,
            'compare_at_price' => $variant->compare_at_price,
            'available' => $variant->availableQuantity(),
            'stocks' => $variant->stockLevels->map(fn (StockLevel $l) => [
                'location' => $l->location,
                'on_hand' => $l->on_hand,
                'reserved' => $l->reserved,
                'safety_stock' => $l->safety_stock,
            ])->values(),
            'shopify_variant_id' => $variant->shopify_variant_id,
            'sync_status' => $variant->sync_status->value,
            'last_synced_at' => $variant->last_synced_at?->toIso8601String(),
        ];
    }
}
