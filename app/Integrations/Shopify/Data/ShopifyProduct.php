<?php

namespace App\Integrations\Shopify\Data;

final readonly class ShopifyProduct
{
    /**
     * @param  list<ShopifyVariant>  $variants
     */
    public function __construct(
        public string $id,
        public string $handle,
        public string $title,
        public array $variants,
    ) {}
}
