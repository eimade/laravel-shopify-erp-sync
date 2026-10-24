<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fiche produit (données PIM) regroupant des variantes.
 *
 * @property int $id
 * @property string $handle
 * @property string $title
 * @property string|null $description
 * @property string|null $vendor
 * @property string|null $product_type
 * @property string|null $shopify_product_id
 * @property-read Collection<int, Variant> $variants
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['handle', 'title', 'description', 'vendor', 'product_type', 'shopify_product_id'];

    /** @return HasMany<Variant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function priceFrom(): float
    {
        return (float) ($this->variants->min('price') ?? 0);
    }

    public function totalAvailable(): int
    {
        $this->loadMissing('variants.stockLevels');

        return (int) $this->variants->sum(fn (Variant $v) => $v->availableQuantity());
    }

    /**
     * Recherche plein texte simple (titre, handle, SKU).
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }
        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', $like)
            ->orWhere('handle', 'like', $like)
            ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', $like)));
    }
}
