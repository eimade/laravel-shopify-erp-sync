<?php

namespace Tests;

use App\Integrations\Shopify\FakeShopifyClient;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function fakeShopify(): FakeShopifyClient
    {
        return $this->app->make(FakeShopifyClient::class);
    }

    /**
     * Crée un SKU dans l'ERP (avec stock) et, optionnellement, le même SKU dans la boutique simulée.
     */
    protected function createSku(string $sku, string $price = '50.00', int $onHand = 10, bool $inShopify = true, ?string $shopifyPrice = null): Variant
    {
        $product = Product::factory()->create(['handle' => strtolower($sku).'-produit']);
        $variant = Variant::factory()->withStock($onHand)->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'price' => $price,
        ]);

        if ($inShopify) {
            $this->fakeShopify()->addProduct($product->handle, $product->title, [
                ['sku' => $sku, 'price' => $shopifyPrice ?? $price, 'quantity' => 0],
            ]);
        }

        return $variant->fresh(['product', 'stockLevels']);
    }
}
