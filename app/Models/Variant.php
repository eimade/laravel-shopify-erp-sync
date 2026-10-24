<?php

namespace App\Models;

use App\Domain\Enums\VariantSyncStatus;
use App\Domain\StockCalculator;
use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Variante vendable. Le SKU est la clé de rapprochement ERP <-> Shopify.
 *
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property string $title
 * @property string $price
 * @property string|null $compare_at_price
 * @property string|null $shopify_variant_id
 * @property string|null $shopify_inventory_item_id
 * @property VariantSyncStatus $sync_status
 * @property Carbon|null $last_synced_at
 * @property-read Product $product
 * @property-read Collection<int, StockLevel> $stockLevels
 */
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id', 'sku', 'title', 'price', 'compare_at_price',
        'shopify_variant_id', 'shopify_inventory_item_id', 'sync_status', 'last_synced_at',
    ];

    protected $attributes = [
        'sync_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'sync_status' => VariantSyncStatus::class,
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<StockLevel, $this> */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /** @return HasMany<Conflict, $this> */
    public function conflicts(): HasMany
    {
        return $this->hasMany(Conflict::class);
    }

    public function isMapped(): bool
    {
        return $this->shopify_variant_id !== null && $this->shopify_inventory_item_id !== null;
    }

    /** Quantité vendable calculée à partir des stocks ERP. */
    public function availableQuantity(): int
    {
        return app(StockCalculator::class)->available(
            $this->stockLevels->map(fn (StockLevel $l) => [
                'on_hand' => $l->on_hand,
                'reserved' => $l->reserved,
                'safety_stock' => $l->safety_stock,
            ])
        );
    }
}
