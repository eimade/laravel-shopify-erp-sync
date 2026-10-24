<?php

namespace App\Domain;

/**
 * Normalise les identifiants Shopify : les webhooks REST envoient des entiers,
 * l'API GraphQL des GID (gid://shopify/ProductVariant/123).
 */
final class ShopifyGid
{
    public static function make(string $type, int|string $id): string
    {
        $id = (string) $id;

        return str_starts_with($id, 'gid://') ? $id : "gid://shopify/{$type}/{$id}";
    }

    public static function numericId(string $gid): string
    {
        $pos = strrpos($gid, '/');

        return $pos === false ? $gid : substr($gid, $pos + 1);
    }
}
