<?php

namespace App\Jobs;

use App\Domain\Enums\SyncType;
use App\Integrations\Shopify\ShopifyClient;
use App\Models\Variant;
use App\Services\SkuMapper;

/**
 * Parcourt tout le catalogue Shopify (pagination par curseur) pour :
 * - rattacher chaque variante Shopify au SKU ERP (mapping SKU <-> variant ID) ;
 * - signaler les écarts de prix (conflits) et les SKU orphelins.
 */
class PullShopifyCatalog extends TrackedSyncJob
{
    public function __construct(public readonly int $pageSize = 50) {}

    public static function type(): SyncType
    {
        return SyncType::PullCatalog;
    }

    public static function fromPayload(array $payload): static
    {
        return new static((int) ($payload['page_size'] ?? 50));
    }

    public function payload(): array
    {
        return ['page_size' => $this->pageSize];
    }

    public function subject(): ?string
    {
        return 'catalogue';
    }

    protected function execute(): array
    {
        $shopify = app(ShopifyClient::class);
        $mapper = app(SkuMapper::class);

        $cursor = null;
        $pages = 0;
        $mapped = 0;
        $conflicts = 0;
        $unknown = [];

        do {
            $page = $shopify->listProducts($cursor, $this->pageSize);
            $pages++;

            foreach ($page->items as $product) {
                foreach ($product->variants as $remote) {
                    $variant = $mapper->map($remote);
                    if ($variant === null) {
                        $unknown[] = $remote->sku ?? $remote->id;

                        continue;
                    }
                    $mapped++;
                    if ($mapper->detectPriceConflict($variant, $remote->price, 'pull_catalog') !== null) {
                        $conflicts++;
                    }
                }
            }

            $cursor = $page->endCursor;
        } while ($page->hasNextPage && $cursor !== null);

        return [
            'pages' => $pages,
            'mapped' => $mapped,
            'conflicts' => $conflicts,
            'unknown_in_erp' => $unknown,
            'erp_not_in_shopify' => Variant::whereNull('shopify_variant_id')->pluck('sku')->all(),
        ];
    }
}
