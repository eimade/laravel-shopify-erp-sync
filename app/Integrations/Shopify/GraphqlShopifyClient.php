<?php

namespace App\Integrations\Shopify;

use App\Integrations\Shopify\Data\OrderData;
use App\Integrations\Shopify\Data\Page;
use App\Integrations\Shopify\Data\ShopifyProduct;
use App\Integrations\Shopify\Data\ShopifyVariant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Implémentation réelle du contrat ShopifyClient via l'Admin API GraphQL.
 *
 * - Authentification par jeton d'application personnalisée (X-Shopify-Access-Token).
 * - Rate limit : CostThrottle (coût de requête) + nouvel essai sur erreur THROTTLED.
 * - Pagination : curseurs pageInfo { hasNextPage endCursor }.
 */
class GraphqlShopifyClient implements ShopifyClient
{
    private const MAX_THROTTLE_RETRIES = 3;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CostThrottle $throttle,
        private readonly string $shopDomain,
        private readonly string $accessToken,
        private readonly string $apiVersion,
        private readonly string $locationId,
    ) {}

    public function shopName(): string
    {
        $data = $this->query('query { shop { name } }', [], 1);

        return (string) $data['shop']['name'];
    }

    public function listProducts(?string $cursor = null, int $first = 50): Page
    {
        $query = <<<'GQL'
        query Products($first: Int!, $after: String) {
          products(first: $first, after: $after) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id handle title
              variants(first: 100) {
                nodes { id sku price inventoryQuantity inventoryItem { id } }
              }
            }
          }
        }
        GQL;

        $data = $this->query($query, ['first' => $first, 'after' => $cursor], 2 + $first * 2);
        $connection = $data['products'];

        $items = [];
        foreach ($connection['nodes'] as $node) {
            $variants = [];
            foreach ($node['variants']['nodes'] as $v) {
                $variants[] = $this->mapVariant($v, $node['id']);
            }
            $items[] = new ShopifyProduct($node['id'], $node['handle'], $node['title'], $variants);
        }

        return new Page($items, (bool) $connection['pageInfo']['hasNextPage'], $connection['pageInfo']['endCursor'] ?? null);
    }

    public function findVariantBySku(string $sku): ?ShopifyVariant
    {
        $query = <<<'GQL'
        query VariantBySku($q: String!) {
          productVariants(first: 1, query: $q) {
            nodes { id sku price inventoryQuantity inventoryItem { id } product { id } }
          }
        }
        GQL;

        $data = $this->query($query, ['q' => 'sku:'.$this->escapeSearch($sku)], 3);
        $node = $data['productVariants']['nodes'][0] ?? null;
        if ($node === null || ($node['sku'] ?? null) !== $sku) {
            return null;
        }

        return $this->mapVariant($node, $node['product']['id']);
    }

    public function updateVariantPrice(string $productId, string $variantId, string $price, ?string $compareAtPrice): void
    {
        $mutation = <<<'GQL'
        mutation UpdatePrice($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
          productVariantsBulkUpdate(productId: $productId, variants: $variants) {
            productVariants { id price }
            userErrors { field message }
          }
        }
        GQL;

        $data = $this->query($mutation, [
            'productId' => $productId,
            'variants' => [['id' => $variantId, 'price' => $price, 'compareAtPrice' => $compareAtPrice]],
        ], 10);

        $errors = $data['productVariantsBulkUpdate']['userErrors'] ?? [];
        if ($errors !== []) {
            throw ShopifyException::userErrors('productVariantsBulkUpdate', $errors);
        }
    }

    public function setAvailableQuantity(string $inventoryItemId, int $quantity): void
    {
        $mutation = <<<'GQL'
        mutation SetQty($input: InventorySetQuantitiesInput!) {
          inventorySetQuantities(input: $input) {
            userErrors { field message }
          }
        }
        GQL;

        $data = $this->query($mutation, ['input' => [
            'name' => 'available',
            'reason' => 'correction',
            'ignoreCompareQuantity' => true,
            'quantities' => [[
                'inventoryItemId' => $inventoryItemId,
                'locationId' => $this->locationId,
                'quantity' => $quantity,
            ]],
        ]], 10);

        $errors = $data['inventorySetQuantities']['userErrors'] ?? [];
        if ($errors !== []) {
            throw ShopifyException::userErrors('inventorySetQuantities', $errors);
        }
    }

    public function listOrders(?string $cursor = null, int $first = 50, ?string $updatedSince = null): Page
    {
        $query = <<<'GQL'
        query Orders($first: Int!, $after: String, $q: String) {
          orders(first: $first, after: $after, query: $q, sortKey: UPDATED_AT) {
            pageInfo { hasNextPage endCursor }
            nodes {
              id name email createdAt currencyCode displayFinancialStatus
              totalPriceSet { shopMoney { amount } }
              lineItems(first: 100) {
                nodes { sku title quantity originalUnitPriceSet { shopMoney { amount } } }
              }
            }
          }
        }
        GQL;

        $data = $this->query($query, [
            'first' => $first,
            'after' => $cursor,
            'q' => $updatedSince ? "updated_at:>='{$updatedSince}'" : null,
        ], 2 + $first * 2);

        $items = [];
        foreach ($data['orders']['nodes'] as $node) {
            $lines = [];
            foreach ($node['lineItems']['nodes'] as $line) {
                $lines[] = [
                    'sku' => $line['sku'] ?: null,
                    'title' => (string) $line['title'],
                    'quantity' => (int) $line['quantity'],
                    'unit_price' => (string) $line['originalUnitPriceSet']['shopMoney']['amount'],
                ];
            }
            $items[] = new OrderData(
                id: $node['id'],
                name: $node['name'],
                email: $node['email'] ?? null,
                currency: $node['currencyCode'],
                totalPrice: (string) $node['totalPriceSet']['shopMoney']['amount'],
                financialStatus: isset($node['displayFinancialStatus']) ? strtolower($node['displayFinancialStatus']) : null,
                placedAt: $node['createdAt'] ?? null,
                lines: $lines,
            );
        }

        return new Page($items, (bool) $data['orders']['pageInfo']['hasNextPage'], $data['orders']['pageInfo']['endCursor'] ?? null);
    }

    /**
     * Exécute une requête GraphQL en respectant le budget de coût.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables, int $estimatedCost): array
    {
        $attempt = 0;

        while (true) {
            $this->throttle->waitFor($estimatedCost);

            try {
                $response = $this->http
                    ->withHeaders(['X-Shopify-Access-Token' => $this->accessToken])
                    ->acceptJson()
                    ->timeout(20)
                    ->post($this->endpoint(), ['query' => $query, 'variables' => $variables]);
            } catch (ConnectionException $e) {
                throw new ShopifyException('Shopify injoignable : '.$e->getMessage());
            }

            if ($response->status() === 429 && $attempt < self::MAX_THROTTLE_RETRIES) {
                $attempt++;
                $this->throttle->backoffAfterThrottled($estimatedCost);

                continue;
            }
            if ($response->status() === 401 || $response->status() === 403) {
                throw new ShopifyException("Accès refusé par Shopify (HTTP {$response->status()}) : vérifier le jeton et les scopes.", retryable: false);
            }
            if ($response->failed()) {
                throw new ShopifyException("Erreur HTTP {$response->status()} de Shopify.", retryable: $response->serverError() || $response->status() === 429);
            }

            $body = (array) $response->json();
            $this->throttle->record($body['extensions'] ?? null);

            $errors = $body['errors'] ?? [];
            if ($errors !== []) {
                $throttled = collect($errors)->contains(fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED');
                if ($throttled && $attempt < self::MAX_THROTTLE_RETRIES) {
                    $attempt++;
                    $requested = (int) ($body['extensions']['cost']['requestedQueryCost'] ?? $estimatedCost);
                    $this->throttle->backoffAfterThrottled($requested);

                    continue;
                }

                $message = collect($errors)->pluck('message')->implode(' ; ');

                throw new ShopifyException('Erreur GraphQL Shopify : '.$message, retryable: $throttled);
            }

            return (array) ($body['data'] ?? []);
        }
    }

    private function endpoint(): string
    {
        return "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/graphql.json";
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function mapVariant(array $node, string $productId): ShopifyVariant
    {
        return new ShopifyVariant(
            id: $node['id'],
            productId: $productId,
            sku: $node['sku'] ?: null,
            price: (string) $node['price'],
            inventoryItemId: $node['inventoryItem']['id'],
            inventoryQuantity: (int) ($node['inventoryQuantity'] ?? 0),
        );
    }

    private function escapeSearch(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
