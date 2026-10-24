<?php

namespace App\Domain\Enums;

enum ConflictResolution: string
{
    /** L'ERP reste la source de vérité : on repousse sa valeur vers Shopify. */
    case KeepErp = 'keep_erp';
    /** La modification faite dans Shopify est validée et reportée dans l'ERP. */
    case AcceptShopify = 'accept_shopify';

    public function label(): string
    {
        return match ($this) {
            self::KeepErp => 'Garder la valeur ERP',
            self::AcceptShopify => 'Accepter la valeur Shopify',
        };
    }
}
