<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $title = ucfirst($this->faker->unique()->words(3, true));

        return [
            'handle' => Str::slug($title).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'title' => $title,
            'description' => $this->faker->sentence(),
            'vendor' => $this->faker->company(),
            'product_type' => 'Vêtements',
        ];
    }
}
