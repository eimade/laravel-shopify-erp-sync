<?php

namespace App\Integrations\Erp;

/** Vue ERP d'un article : prix de vente et quantité vendable. */
final readonly class ErpItem
{
    public function __construct(
        public string $sku,
        public string $price,
        public ?string $compareAtPrice,
        public int $available,
    ) {}
}
