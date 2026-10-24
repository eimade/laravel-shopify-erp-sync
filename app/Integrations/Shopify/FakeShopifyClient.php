<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Data\OrderData;
use App\Integrations\Shopify\Data\Page;
use App\Integrations\Shopify\Data\ShopifyProduct;
use App\Integrations\Shopify\Data\ShopifyVariant;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Faux client Shopify respectant le même contrat que le client GraphQL.
 *
 * L'état de la "boutique" est conservé dans le cache applicatif (Redis en Docker),
 * ce qui permet au serveur web et au worker de partager la même boutique simulée.
 * Utilisé pour la démo hors-ligne et les tests : aucun compte Shopify requis.
 *
 * @phpstan-type FakeVariant array{id: string, sku: ?string, price: string, compareAtPrice: ?string, inventoryItemId: string, inventoryQuantity: int}
 * @phpstan-type FakeProduct array{id: string, handle: string, title: string, variants: array<string, FakeVariant>}
 * @phpstan-type FakeState array{shop: string, nextId: int, products: array<string, FakeProduct>, orders: list<array<string, mixed>>, calls: int}
 */
class FakeShopifyClient implements ShopifyClient
{
    public const CACHE_KEY = 'synchub:fake-shopify:state';

    public function __construct(private readonly Cache $cache) {}

    public function shopName(): string
    {
        return $this->mutate(fn (array $s) => $s)['shop'];
    }

    public function listProducts(?string $cursor = null, int $first = 50): Page
    {
        $products = array_values($this->mutate(fn (array $s) => $s)['products']);
        $offset = $cursor === null ? 0 : (int) base64_decode($cursor);
        $slice = array_slice($products, $offset, $first);

        $items = array_map(fn (array $p) => new ShopifyProduct(
            $p['id'],
            $p['handle'],
            $p['title'],
            array_values(array_map(fn (array $v) => $this->toVariant($v, $p['id']), $p['variants'])),
        ), $slice);

        $next = $offset + count($slice);

        return new Page($items, $next < count($products), $next < count($products) ? base64_encode((string) $next) : null);
    }

    public function findVariantBySku(string $sku): ?ShopifyVariant
    {
        foreach ($this->mutate(fn (array $s) => $s)['products'] as $product) {
            foreach ($product['variants'] as $variant) {
                if ($variant['sku'] === $sku) {
                    return $this->toVariant($variant, $product['id']);
                }
            }
        }

        return null;
    }

    public function updateVariantPrice(string $productId, string $variantId, string $price, ?string $compareAtPrice): void
    {
        $this->mutate(function (array $state) use ($productId, $variantId, $price, $compareAtPrice) {
            if (! isset($state['products'][$productId]['variants'][$variantId])) {
                throw new ShopifyException("productVariantsBulkUpdate refusé par Shopify : variante {$variantId} introuvable", retryable: false);
            }
            $state['products'][$productId]['variants'][$variantId]['price'] = $price;
            $state['products'][$productId]['variants'][$variantId]['compareAtPrice'] = $compareAtPrice;

            return $state;
        });
    }

    public function setAvailableQuantity(string $inventoryItemId, int $quantity): void
    {
        $this->mutate(function (array $state) use ($inventoryItemId, $quantity) {
            foreach ($state['products'] as $pid => $product) {
                foreach ($product['variants'] as $vid => $variant) {
                    if ($variant['inventoryItemId'] === $inventoryItemId) {
                        $state['products'][$pid]['variants'][$vid]['inventoryQuantity'] = $quantity;

                        return $state;
                    }
                }
            }

            throw new ShopifyException("inventorySetQuantities refusé par Shopify : article de stock {$inventoryItemId} introuvable", retryable: false);
        });
    }

    public function listOrders(?string $cursor = null, int $first = 50, ?string $updatedSince = null): Page
    {
        $orders = $this->mutate(fn (array $s) => $s)['orders'];
        if ($updatedSince !== null) {
            $orders = array_values(array_filter($orders, fn (array $o) => ($o['placedAt'] ?? '') >= $updatedSince));
        }
        $offset = $cursor === null ? 0 : (int) base64_decode($cursor);
        $slice = array_slice($orders, $offset, $first);
        $next = $offset + count($slice);

        return new Page(
            array_map(fn (array $o) => OrderData::fromArray($o), $slice),
            $next < count($orders),
            $next < count($orders) ? base64_encode((string) $next) : null,
        );
    }

    // ----- Outils de simulation (démo / tests), hors contrat -----

    /**
     * Crée un produit dans la boutique simulée et renvoie son GID.
     *
     * @param  list<array{sku: string, price: string, quantity?: int}>  $variants
     */
    public function addProduct(string $handle, string $title, array $variants): string
    {
        $productId = '';
        $this->mutate(function (array $state) use ($handle, $title, $variants, &$productId) {
            $productId = 'gid://shopify/Product/'.$state['nextId']++;
            $state['products'][$productId] = ['id' => $productId, 'handle' => $handle, 'title' => $title, 'variants' => []];
            foreach ($variants as $v) {
                $n = $state['nextId']++;
                $variantId = "gid://shopify/ProductVariant/{$n}";
                $state['products'][$productId]['variants'][$variantId] = [
                    'id' => $variantId,
                    'sku' => $v['sku'],
                    'price' => $v['price'],
                    'compareAtPrice' => null,
                    'inventoryItemId' => "gid://shopify/InventoryItem/{$n}",
                    'inventoryQuantity' => $v['quantity'] ?? 0,
                ];
            }

            return $state;
        });

        return $productId;
    }

    public function addOrder(OrderData $order): void
    {
        $this->mutate(function (array $state) use ($order) {
            $state['orders'][] = $order->toArray();

            return $state;
        });
    }

    /** Simule une modification manuelle de prix faite dans l'admin Shopify. */
    public function setPriceBySku(string $sku, string $price): ShopifyVariant
    {
        $variant = $this->findVariantBySku($sku) ?? throw new ShopifyException("SKU {$sku} absent de la boutique simulée", false);
        $this->updateVariantPrice($variant->productId, $variant->id, $price, null);

        return $this->findVariantBySku($sku) ?? $variant;
    }

    /** @return FakeState */
    public function state(): array
    {
        return $this->mutate(fn (array $s) => $s);
    }

    public function reset(string $shopName = 'Boutique démo SyncHub'): void
    {
        $this->cache->forever(self::CACHE_KEY, $this->emptyState($shopName));
    }

    /**
     * Lit l'état, applique une transformation et l'enregistre. Compte les appels API.
     *
     * @param  callable(FakeState): FakeState  $fn
     * @return FakeState
     */
    private function mutate(callable $fn): array
    {
        /** @var FakeState $state */
        $state = $this->cache->get(self::CACHE_KEY) ?? $this->emptyState();
        $state = $fn($state);
        $state['calls']++;
        $this->cache->forever(self::CACHE_KEY, $state);

        return $state;
    }

    /** @return FakeState */
    private function emptyState(string $shopName = 'Boutique démo SyncHub'): array
    {
        return ['shop' => $shopName, 'nextId' => 1000, 'products' => [], 'orders' => [], 'calls' => 0];
    }

    /**
     * @param  FakeVariant  $v
     */
    private function toVariant(array $v, string $productId): ShopifyVariant
    {
        return new ShopifyVariant($v['id'], $productId, $v['sku'], $v['price'], $v['inventoryItemId'], $v['inventoryQuantity']);
    }
}
