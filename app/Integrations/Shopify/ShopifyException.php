<?php

namespace App\Integrations\Shopify;

use RuntimeException;

/**
 * Erreur renvoyée par Shopify (HTTP, GraphQL ou userErrors).
 * $retryable indique si un nouvel essai a une chance d'aboutir (réseau, 5xx, throttling).
 */
class ShopifyException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<array{field?: list<string>|string|null, message?: string}>  $errors
     */
    public static function userErrors(string $operation, array $errors): self
    {
        $messages = array_map(
            fn (array $e) => trim(implode('.', (array) ($e['field'] ?? [])).' '.($e['message'] ?? '')),
            $errors
        );

        return new self("{$operation} refusé par Shopify : ".implode(' ; ', $messages), retryable: false);
    }
}
