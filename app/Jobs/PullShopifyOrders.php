<?php

namespace App\Jobs;

use App\Domain\Enums\SyncType;
use App\Integrations\Shopify\ShopifyClient;
use App\Services\OrderImporter;

/**
 * Rattrapage : relit les commandes Shopify récentes (pagination par curseur)
 * pour remonter celles dont le webhook aurait été perdu. Idempotent.
 */
final class PullShopifyOrders extends TrackedSyncJob
{
    public function __construct(public readonly ?string $since = null) {}

    public static function type(): SyncType
    {
        return SyncType::PullOrders;
    }

    public static function fromPayload(array $payload): static
    {
        return new self($payload['since'] ?? null);
    }

    public function payload(): array
    {
        return ['since' => $this->since];
    }

    public function subject(): string
    {
        return $this->since ? 'depuis '.$this->since : 'toutes';
    }

    protected function execute(): array
    {
        $shopify = app(ShopifyClient::class);
        $importer = app(OrderImporter::class);

        $cursor = null;
        $seen = 0;
        $created = [];

        do {
            $page = $shopify->listOrders($cursor, 50, $this->since);
            foreach ($page->items as $order) {
                $seen++;
                if ($importer->import($order)['created']) {
                    $created[] = $order->name;
                }
            }
            $cursor = $page->endCursor;
        } while ($page->hasNextPage && $cursor !== null);

        return ['seen' => $seen, 'created' => $created];
    }
}
