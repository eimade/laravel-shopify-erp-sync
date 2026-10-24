<?php

namespace App\Integrations\Shopify\Data;

final readonly class ShopifyVariant
{
    public function __construct(
        public string $id,
        public string $productId,
        public ?string $sku,
        public string $price,
        public string $inventoryItemId,
        public int $inventoryQuantity,
    ) {}
}
