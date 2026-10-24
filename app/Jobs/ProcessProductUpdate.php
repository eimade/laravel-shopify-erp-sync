<?php

namespace App\Jobs;

use App\Domain\Enums\SyncType;
use App\Domain\ShopifyGid;
use App\Integrations\Shopify\Data\ShopifyVariant;
use App\Services\SkuMapper;

/**
 * Traite le webhook products/update : met à jour le mapping et
 * ouvre un conflit si un prix a été modifié directement dans Shopify.
 */
final class ProcessProductUpdate extends TrackedSyncJob
{
    /**
     * @param  array<string, mixed>  $product  payload du webhook
     */
    public function __construct(public readonly array $product) {}

    public static function type(): SyncType
    {
        return SyncType::ProductUpdate;
    }

    public static function fromPayload(array $payload): static
    {
        return new self($payload);
    }

    public function payload(): array
    {
        return $this->product;
    }

    public function subject(): ?string
    {
        return isset($this->product['handle']) ? (string) $this->product['handle'] : null;
    }

    protected function execute(): array
    {
        $mapper = app(SkuMapper::class);
        $productId = (string) ($this->product['admin_graphql_api_id'] ?? ShopifyGid::make('Product', $this->product['id'] ?? ''));

        $mapped = [];
        $conflicts = [];
        $ignored = [];

        foreach ((array) ($this->product['variants'] ?? []) as $v) {
            $remote = new ShopifyVariant(
                id: (string) ($v['admin_graphql_api_id'] ?? ShopifyGid::make('ProductVariant', $v['id'])),
                productId: $productId,
                sku: $v['sku'] ?? null,
                price: (string) ($v['price'] ?? '0'),
                inventoryItemId: ShopifyGid::make('InventoryItem', $v['inventory_item_id'] ?? '0'),
                inventoryQuantity: (int) ($v['inventory_quantity'] ?? 0),
            );

            $variant = $mapper->map($remote);
            if ($variant === null) {
                $ignored[] = $remote->sku ?? $remote->id;

                continue;
            }
            $mapped[] = $variant->sku;
            if ($mapper->detectPriceConflict($variant, $remote->price, 'webhook:products/update') !== null) {
                $conflicts[] = $variant->sku;
            }
        }

        return ['mapped' => $mapped, 'conflicts' => $conflicts, 'ignored' => $ignored];
    }
}
