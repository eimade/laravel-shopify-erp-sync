<?php

namespace App\Jobs;

use App\Domain\Enums\SyncType;
use App\Integrations\Shopify\Data\OrderData;
use App\Services\OrderImporter;

/**
 * Remonte une commande Shopify (webhook orders/create) dans l'ERP.
 */
final class ImportShopifyOrder extends TrackedSyncJob
{
    /**
     * @param  array<string, mixed>  $order  OrderData sérialisée
     */
    public function __construct(public readonly array $order) {}

    public static function type(): SyncType
    {
        return SyncType::ImportOrder;
    }

    public static function fromPayload(array $payload): static
    {
        return new self($payload);
    }

    public function payload(): array
    {
        return $this->order;
    }

    public function subject(): ?string
    {
        return isset($this->order['name']) ? (string) $this->order['name'] : null;
    }

    protected function execute(): array
    {
        return app(OrderImporter::class)->import(OrderData::fromArray($this->order));
    }
}
