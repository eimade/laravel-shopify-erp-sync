<?php

namespace Tests\Feature;

use App\Jobs\PushVariantToShopify;
use App\Services\SyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class CatalogueGraphqlTest extends TestCase
{
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_catalogue_exposes_unified_product_data(): void
    {
        $variant = $this->createSku('ROBE-LIN-S', '79.90', onHand: 12);
        $variant->product->update(['title' => 'Robe en lin été', 'vendor' => 'Maison Lumen']);
        $this->app->make(SyncDispatcher::class)->dispatch(new PushVariantToShopify('ROBE-LIN-S'));
        $this->createSku('SERUM-VITC-30', '34.50');

        $this->graphQL(/** @lang GraphQL */ '
            query ($search: String) {
              products(search: $search, first: 10) {
                data {
                  title vendor priceFrom totalAvailable
                  variants { sku price available syncStatus shopifyVariantId stockLevels { location onHand } }
                }
                paginatorInfo { total }
              }
            }', ['search' => 'robe'])
            ->assertJsonPath('data.products.paginatorInfo.total', 1)
            ->assertJsonPath('data.products.data.0.title', 'Robe en lin été')
            ->assertJsonPath('data.products.data.0.priceFrom', 79.9)
            ->assertJsonPath('data.products.data.0.totalAvailable', 12)
            ->assertJsonPath('data.products.data.0.variants.0.syncStatus', 'Synced')
            ->assertJsonPath('data.products.data.0.variants.0.stockLevels.0.onHand', 12);
    }

    public function test_variant_lookup_by_sku(): void
    {
        $this->createSku('SERUM-VITC-30', '34.50', onHand: 7);

        $this->graphQL('{ variant(sku: "SERUM-VITC-30") { sku price available product { handle } } }')
            ->assertJsonPath('data.variant.sku', 'SERUM-VITC-30')
            ->assertJsonPath('data.variant.available', 7);

        $this->graphQL('{ variant(sku: "INCONNU") { sku } }')->assertJsonPath('data.variant', null);
    }

    public function test_sync_health_reports_connector_state(): void
    {
        $this->createSku('ERP-ONLY', inShopify: false);

        $this->graphQL('{ syncHealth { shopifyOk shopifyDriver shopName unmappedVariants deadLetters successRate24h } }')
            ->assertJsonPath('data.syncHealth.shopifyOk', true)
            ->assertJsonPath('data.syncHealth.shopifyDriver', 'fake')
            ->assertJsonPath('data.syncHealth.unmappedVariants', 1)
            ->assertJsonPath('data.syncHealth.successRate24h', 100);
    }

    public function test_api_is_read_only(): void
    {
        $this->graphQL('mutation { deleteProduct(id: 1) { id } }')->assertGraphQLErrorMessage('Schema is not configured for mutations.');
    }
}
