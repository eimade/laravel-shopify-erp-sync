<?php

namespace Tests\Feature;

use App\Integrations\Shopify\CostThrottle;
use App\Integrations\Shopify\GraphqlShopifyClient;
use App\Integrations\Shopify\ShopifyException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Client GraphQL réel testé contre des réponses Shopify simulées (Http::fake) : aucun appel réseau.
 */
class GraphqlShopifyClientTest extends TestCase
{
    private const ENDPOINT = 'https://demo.myshopify.com/admin/api/2025-07/graphql.json';

    /** @var list<float> */
    private array $sleeps = [];

    private function client(): GraphqlShopifyClient
    {
        $throttle = new CostThrottle(100, function (float $s) {
            $this->sleeps[] = $s;
        });

        return new GraphqlShopifyClient($this->app->make(HttpFactory::class), $throttle, 'demo.myshopify.com', 'shpat_test', '2025-07', 'gid://shopify/Location/1');
    }

    private function cost(float $available = 980): array
    {
        return ['cost' => ['requestedQueryCost' => 12, 'actualQueryCost' => 6, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => $available, 'restoreRate' => 50]]];
    }

    private function productsPage(array $nodes, bool $hasNext, ?string $cursor): array
    {
        return ['data' => ['products' => ['pageInfo' => ['hasNextPage' => $hasNext, 'endCursor' => $cursor], 'nodes' => $nodes]], 'extensions' => $this->cost()];
    }

    private function productNode(int $id, string $sku): array
    {
        return [
            'id' => "gid://shopify/Product/{$id}", 'handle' => "p-{$id}", 'title' => "Produit {$id}",
            'variants' => ['nodes' => [[
                'id' => "gid://shopify/ProductVariant/{$id}0", 'sku' => $sku, 'price' => '19.90',
                'inventoryQuantity' => 4, 'inventoryItem' => ['id' => "gid://shopify/InventoryItem/{$id}0"],
            ]]],
        ];
    }

    public function test_lists_products_with_cursor_pagination_and_auth_header(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->productsPage([$this->productNode(1, 'A')], true, 'CURSOR1'))
            ->push($this->productsPage([$this->productNode(2, 'B')], false, null)),
        ]);
        $client = $this->client();

        $first = $client->listProducts(null, 1);
        $second = $client->listProducts($first->endCursor, 1);

        $this->assertTrue($first->hasNextPage);
        $this->assertSame('A', $first->items[0]->variants[0]->sku);
        $this->assertSame('gid://shopify/InventoryItem/10', $first->items[0]->variants[0]->inventoryItemId);
        $this->assertFalse($second->hasNextPage);

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Shopify-Access-Token', 'shpat_test') && $r['variables']['after'] === null);
        Http::assertSent(fn (Request $r) => $r['variables']['after'] === 'CURSOR1');
    }

    public function test_retries_after_throttled_error(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]], 'extensions' => $this->cost(0)])
            ->push(['data' => ['shop' => ['name' => 'Démo']], 'extensions' => $this->cost(990)]),
        ]);

        $this->assertSame('Démo', $this->client()->shopName());
        $this->assertSame(1.0, $this->sleeps[0]);
        Http::assertSentCount(2);
    }

    public function test_waits_before_request_when_bucket_is_low(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['data' => ['shop' => ['name' => 'Démo']], 'extensions' => $this->cost(20)])
            ->push(['data' => ['shop' => ['name' => 'Démo']], 'extensions' => $this->cost(500)]),
        ]);
        $client = $this->client();

        $client->shopName();
        $client->shopName();

        // 1 point demandé + 100 de marge - 20 disponibles = 81 points à 50 pts/s
        $this->assertSame([1.62], $this->sleeps);
    }

    public function test_user_errors_are_not_retryable(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'data' => ['productVariantsBulkUpdate' => ['productVariants' => null, 'userErrors' => [['field' => ['variants', '0', 'price'], 'message' => 'Price must be positive']]]],
            'extensions' => $this->cost(),
        ])]);

        try {
            $this->client()->updateVariantPrice('gid://shopify/Product/1', 'gid://shopify/ProductVariant/10', '-1', null);
            $this->fail('Exception attendue');
        } catch (ShopifyException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('Price must be positive', $e->getMessage());
        }
    }

    public function test_inventory_mutation_targets_configured_location(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => ['inventorySetQuantities' => ['userErrors' => []]], 'extensions' => $this->cost()])]);

        $this->client()->setAvailableQuantity('gid://shopify/InventoryItem/10', 7);

        Http::assertSent(fn (Request $r) => $r['variables']['input']['quantities'][0] === [
            'inventoryItemId' => 'gid://shopify/InventoryItem/10',
            'locationId' => 'gid://shopify/Location/1',
            'quantity' => 7,
        ]);
    }

    public function test_authentication_failure_is_not_retryable_but_server_error_is(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()->push([], 401)->push([], 502)]);
        $client = $this->client();

        try {
            $client->shopName();
        } catch (ShopifyException $e) {
            $this->assertFalse($e->retryable);
        }
        try {
            $client->shopName();
        } catch (ShopifyException $e) {
            $this->assertTrue($e->retryable);
        }
        $this->assertSame(2, Http::recorded()->count());
    }

    public function test_find_variant_by_sku_requires_exact_match(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => ['productVariants' => ['nodes' => [[
            'id' => 'gid://shopify/ProductVariant/10', 'sku' => 'ROBE-LIN-SM', 'price' => '1.00', 'inventoryQuantity' => 0,
            'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/10'], 'product' => ['id' => 'gid://shopify/Product/1'],
        ]]]], 'extensions' => $this->cost()])]);

        $this->assertNull($this->client()->findVariantBySku('ROBE-LIN-S'));
    }
}
