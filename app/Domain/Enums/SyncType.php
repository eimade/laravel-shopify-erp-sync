<?php

namespace App\Domain\Enums;

enum SyncType: string
{
    case PushVariant = 'push_variant';
    case PullCatalog = 'pull_catalog';
    case ProductUpdate = 'product_update';
    case ImportOrder = 'import_order';
    case PullOrders = 'pull_orders';

    public function label(): string
    {
        return match ($this) {
            self::PushVariant => 'Prix/stock ERP → Shopify',
            self::PullCatalog => 'Catalogue Shopify → mapping SKU',
            self::ProductUpdate => 'Webhook products/update',
            self::ImportOrder => 'Commande Shopify → ERP',
            self::PullOrders => 'Rattrapage commandes',
        };
    }

    public function direction(): string
    {
        return $this === self::PushVariant ? 'erp_to_shopify' : 'shopify_to_erp';
    }
}
