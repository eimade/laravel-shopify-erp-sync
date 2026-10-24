<?php

namespace App\Integrations\Shopify\Data;

use App\Domain\ShopifyGid;
use InvalidArgumentException;

/**
 * Commande normalisée, quelle que soit sa source (webhook REST ou requête GraphQL).
 */
final readonly class OrderData
{
    /**
     * @param  list<array{sku: ?string, title: string, quantity: int, unit_price: string}>  $lines
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public string $currency,
        public string $totalPrice,
        public ?string $financialStatus,
        public ?string $placedAt,
        public array $lines,
    ) {}

    /**
     * Construit la commande depuis le payload du webhook orders/create.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromWebhook(array $payload): self
    {
        if (! isset($payload['id'], $payload['name'], $payload['line_items']) || ! is_array($payload['line_items'])) {
            throw new InvalidArgumentException('Payload de commande incomplet (id, name, line_items requis).');
        }

        $lines = [];
        foreach ($payload['line_items'] as $item) {
            $lines[] = [
                'sku' => isset($item['sku']) && $item['sku'] !== '' ? (string) $item['sku'] : null,
                'title' => (string) ($item['title'] ?? $item['name'] ?? 'Article'),
                'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
                'unit_price' => (string) ($item['price'] ?? '0'),
            ];
        }

        return new self(
            id: (string) ($payload['admin_graphql_api_id'] ?? ShopifyGid::make('Order', $payload['id'])),
            name: (string) $payload['name'],
            email: isset($payload['email']) ? (string) $payload['email'] : null,
            currency: (string) ($payload['currency'] ?? 'EUR'),
            totalPrice: (string) ($payload['total_price'] ?? '0'),
            financialStatus: isset($payload['financial_status']) ? (string) $payload['financial_status'] : null,
            placedAt: isset($payload['created_at']) ? (string) $payload['created_at'] : null,
            lines: $lines,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            email: $data['email'] ?? null,
            currency: (string) $data['currency'],
            totalPrice: (string) $data['totalPrice'],
            financialStatus: $data['financialStatus'] ?? null,
            placedAt: $data['placedAt'] ?? null,
            lines: $data['lines'],
        );
    }
}
