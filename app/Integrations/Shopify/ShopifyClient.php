<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Data\OrderData;
use App\Integrations\Shopify\Data\Page;
use App\Integrations\Shopify\Data\ShopifyProduct;
use App\Integrations\Shopify\Data\ShopifyVariant;

/**
 * Contrat d'accès à l'Admin API Shopify.
 *
 * Deux implémentations : GraphqlShopifyClient (boutique réelle) et FakeShopifyClient
 * (démo hors-ligne et tests). Le reste de l'application ne dépend que de ce contrat.
 */
interface ShopifyClient
{
    /** Nom de la boutique : sert de sonde de santé du connecteur. */
    public function shopName(): string;

    /** @return Page<ShopifyProduct> */
    public function listProducts(?string $cursor = null, int $first = 50): Page;

    public function findVariantBySku(string $sku): ?ShopifyVariant;

    public function updateVariantPrice(string $productId, string $variantId, string $price, ?string $compareAtPrice): void;

    public function setAvailableQuantity(string $inventoryItemId, int $quantity): void;

    /** @return Page<OrderData> */
    public function listOrders(?string $cursor = null, int $first = 50, ?string $updatedSince = null): Page;
}
