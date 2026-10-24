<?php

namespace Tests\Feature;

use App\Jobs\PushVariantToShopify;
use App\Models\StockLevel;
use App\Models\SyncRun;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ErpApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['X-Erp-Token' => 'test-erp-token', 'Accept' => 'application/json'];

    public function test_requires_a_valid_token(): void
    {
        $this->getJson('/api/erp/items')->assertStatus(401);
        $this->getJson('/api/erp/items', ['X-Erp-Token' => 'wrong'])->assertStatus(401);
    }

    public function test_lists_and_filters_items(): void
    {
        $this->createSku('ROBE-LIN-S', '79.90', onHand: 12);
        $this->createSku('SERUM-VITC-30', '34.50');

        $this->getJson('/api/erp/items?search=ROBE', self::HEADERS)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'ROBE-LIN-S')
            ->assertJsonPath('data.0.available', 12)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_show_returns_404_for_unknown_sku(): void
    {
        $this->getJson('/api/erp/items/NOPE', self::HEADERS)->assertNotFound()->assertJsonPath('message', 'Ressource introuvable.');
    }

    public function test_update_price_and_stock_triggers_shopify_sync(): void
    {
        Queue::fake();
        $this->createSku('ROBE-LIN-S', '79.90', onHand: 12);

        $this->patchJson('/api/erp/items/robe-lin-s', [
            'price' => 69.9,
            'stocks' => [['location' => 'entrepot-lyon', 'on_hand' => 5], ['location' => 'boutique-paris', 'on_hand' => 2]],
        ], self::HEADERS)
            ->assertStatus(202)
            ->assertJsonPath('data.price', '69.90')
            ->assertJsonPath('data.available', 7)
            ->assertJsonStructure(['sync_run_id']);

        Queue::assertPushed(PushVariantToShopify::class, fn ($job) => $job->sku === 'ROBE-LIN-S');
        $this->assertSame(2, StockLevel::count());
    }

    public function test_update_end_to_end_pushes_to_fake_shopify(): void
    {
        $this->createSku('ROBE-LIN-S', '79.90', onHand: 12);

        $this->patchJson('/api/erp/items/ROBE-LIN-S', ['stocks' => [['location' => 'entrepot-lyon', 'on_hand' => 0]]], self::HEADERS)
            ->assertStatus(202);

        $this->assertSame(0, $this->fakeShopify()->findVariantBySku('ROBE-LIN-S')->inventoryQuantity);
        $this->assertSame('succeeded', SyncRun::sole()->status->value);
    }

    public function test_update_validation(): void
    {
        $this->createSku('ROBE-LIN-S', '79.90');

        $this->patchJson('/api/erp/items/ROBE-LIN-S', ['price' => -3, 'stocks' => [['location' => 'x', 'on_hand' => 'abc']]], self::HEADERS)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price', 'stocks.0.on_hand']);

        $this->patchJson('/api/erp/items/ROBE-LIN-S', [], self::HEADERS)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payload']);

        $this->patchJson('/api/erp/items/ROBE-LIN-S', ['compare_at_price' => 50], self::HEADERS)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['compare_at_price']);
    }

    public function test_csv_import_reports_invalid_lines_and_syncs_changed_skus(): void
    {
        Queue::fake();
        $csv = implode("\n", [
            'sku;product_handle;product_title;variant_title;price;compare_at_price;location;on_hand;safety_stock',
            'robe-lin-s;robe-lin-ete;Robe en lin;S;79,90;;entrepot-lyon;12;2',
            'ROBE-LIN-S;robe-lin-ete;Robe en lin;S;79.90;;boutique-paris;3;1',
            'BAD;Handle Invalide;Produit;;abc;;entrepot-lyon;-1;',
        ]);
        $file = UploadedFile::fake()->createWithContent('catalogue.csv', $csv);

        $this->post('/api/erp/import', ['file' => $file], self::HEADERS)
            ->assertStatus(207)
            ->assertJsonPath('rows', 3)
            ->assertJsonPath('created', 1)
            ->assertJsonPath('changed_skus', ['ROBE-LIN-S'])
            ->assertJsonPath('errors.0.line', 4);

        $variant = Variant::with('stockLevels')->sole();
        $this->assertSame('79.90', $variant->price);
        $this->assertSame(12, $variant->availableQuantity());
        Queue::assertPushed(PushVariantToShopify::class, 1);
    }

    public function test_csv_with_missing_columns_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('catalogue.csv', "sku;price\nA;1");

        $this->post('/api/erp/import', ['file' => $file], self::HEADERS)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Colonnes manquantes : product_handle, product_title, location, on_hand');
    }

    public function test_sync_journal_listing_and_replay_endpoint(): void
    {
        $this->createSku('FOUL-SOIE-01', inShopify: false);
        $this->patchJson('/api/erp/items/FOUL-SOIE-01', ['price' => 40], self::HEADERS)->assertStatus(202);
        $dead = SyncRun::sole();

        $this->getJson('/api/erp/sync-runs?status=dead', self::HEADERS)
            ->assertOk()
            ->assertJsonPath('data.0.id', $dead->id);

        $this->postJson("/api/erp/sync-runs/{$dead->id}/replay", [], self::HEADERS)
            ->assertStatus(202)
            ->assertJsonPath('replayed_from_id', $dead->id);

        // La cause n'est pas corrigée : la relance repart elle aussi en dead-letter.
        $this->assertSame(2, SyncRun::where('status', 'dead')->count());
    }
}
