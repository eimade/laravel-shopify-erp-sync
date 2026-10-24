<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    protected $model = Variant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper($this->faker->unique()->bothify('SKU-####-??')),
            'title' => $this->faker->randomElement(['S', 'M', 'L', 'XL']),
            'price' => $this->faker->randomFloat(2, 10, 150),
            'compare_at_price' => null,
        ];
    }

    /** Ajoute un niveau de stock dans un entrepôt. */
    public function withStock(int $onHand, string $location = 'entrepot-lyon', int $safety = 0): static
    {
        return $this->afterCreating(fn (Variant $variant) => StockLevel::create([
            'variant_id' => $variant->id,
            'location' => $location,
            'on_hand' => $onHand,
            'safety_stock' => $safety,
        ]));
    }

    public function mapped(string $variantId, string $inventoryItemId): static
    {
        return $this->state(['shopify_variant_id' => $variantId, 'shopify_inventory_item_id' => $inventoryItemId]);
    }
}
