<?php

namespace Tests\Feature;

use App\Domain\Enums\ConflictResolution;
use App\Domain\Enums\SyncStatus;
use App\Domain\Enums\VariantSyncStatus;
use App\Integrations\Shopify\Data\OrderData;
use App\Integrations\Shopify\ShopifyClient;
use App\Integrations\Shopify\ShopifyException;
use App\Jobs\PullShopifyCatalog;
use App\Jobs\PullShopifyOrders;
use App\Jobs\PushVariantToShopify;
use App\Models\Conflict;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\SyncRun;
use App\Notifications\SyncJobDeadLettered;
use App\Services\ConflictResolver;
use App\Services\SyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class SyncJobsTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcher(): SyncDispatcher
    {
        return $this->app->make(SyncDispatcher::class);
    }

    public function test_push_sends_erp_price_and_available_stock_to_shopify(): void
    {
        $variant = $this->createSku('ROBE-LIN-M', '79.90', onHand: 20, shopifyPrice: '85.00');
        $variant->stockLevels()->first()->update(['safety_stock' => 2, 'reserved' => 1]);

        $run = $this->dispatcher()->dispatch(new PushVariantToShopify('ROBE-LIN-M'));

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->attempts);
        $this->assertSame(['shopify_variant_id' => $variant->fresh()->shopify_variant_id, 'price' => '79.90', 'available' => 17], $run->result);

        $remote = $this->fakeShopify()->findVariantBySku('ROBE-LIN-M');
        $this->assertSame('79.90', $remote->price);
        $this->assertSame(17, $remote->inventoryQuantity);
        $this->assertSame(VariantSyncStatus::Synced, $variant->fresh()->sync_status);
    }

    public function test_sku_missing_in_shopify_goes_to_dead_letter_and_alerts_the_team(): void
    {
        Notification::fake();
        $variant = $this->createSku('FOUL-SOIE-01', inShopify: false);

        $run = $this->dispatcher()->dispatch(new PushVariantToShopify('FOUL-SOIE-01'));

        $this->assertSame(SyncStatus::Dead, $run->status);
        $this->assertStringContainsString('introuvable dans Shopify', (string) $run->error);
        $this->assertSame(VariantSyncStatus::Unmapped, $variant->fresh()->sync_status);
        Notification::assertSentOnDemand(
            SyncJobDeadLettered::class,
            fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'ops@example.com'
                && $notification->run->is($run),
        );
    }

    public function test_transient_shopify_error_is_retried_with_exponential_backoff(): void
    {
        $this->createSku('ROBE-LIN-S');
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('findVariantBySku')->andThrow(new ShopifyException('Erreur HTTP 503 de Shopify.', retryable: true));
        $this->app->instance(ShopifyClient::class, $client);

        Queue::fake();
        $run = $this->dispatcher()->dispatch(new PushVariantToShopify('ROBE-LIN-S'));
        $job = (new PushVariantToShopify('ROBE-LIN-S'))->withFakeQueueInteractions();
        $job->syncRunId = $run->id;

        try {
            $job->handle();
            $this->fail('Une exception devait être relancée pour déclencher le retry.');
        } catch (ShopifyException) {
            // attendu : la file d'attente reprogrammera le job
        }

        $this->assertSame(SyncStatus::Failed, $run->fresh()->status);
        $this->assertSame([10, 30, 90, 270], $job->backoff());
        $this->assertSame(5, $job->tries);
    }

    public function test_exhausted_retries_mark_the_run_dead(): void
    {
        Notification::fake();
        Queue::fake();
        $this->createSku('ROBE-LIN-S');
        $run = $this->dispatcher()->dispatch(new PushVariantToShopify('ROBE-LIN-S'));
        $job = new PushVariantToShopify('ROBE-LIN-S');
        $job->syncRunId = $run->id;

        $job->failed(new ShopifyException('Erreur HTTP 503 de Shopify.'));

        $this->assertSame(SyncStatus::Dead, $run->fresh()->status);
        Notification::assertSentOnDemandTimes(SyncJobDeadLettered::class, 1);
    }

    public function test_dead_run_can_be_replayed_once_the_cause_is_fixed(): void
    {
        Notification::fake();
        $this->createSku('FOUL-SOIE-01', inShopify: false);
        $dead = $this->dispatcher()->dispatch(new PushVariantToShopify('FOUL-SOIE-01'));
        $this->assertSame(SyncStatus::Dead, $dead->status);

        // Correction : la variante est créée dans Shopify.
        $this->fakeShopify()->addProduct('foulard', 'Foulard', [['sku' => 'FOUL-SOIE-01', 'price' => '45.00']]);
        $replay = $this->dispatcher()->replay($dead);

        $this->assertSame(SyncStatus::Succeeded, $replay->status);
        $this->assertSame($dead->id, $replay->replayed_from_id);
    }

    public function test_successful_run_cannot_be_replayed(): void
    {
        $this->createSku('ROBE-LIN-S');
        $run = $this->dispatcher()->dispatch(new PushVariantToShopify('ROBE-LIN-S'));

        $this->expectException(InvalidArgumentException::class);
        $this->dispatcher()->replay($run);
    }

    public function test_pull_catalog_paginates_maps_skus_and_detects_conflicts(): void
    {
        $this->createSku('A-1', '10.00');
        $this->createSku('B-1', '20.00', shopifyPrice: '18.00');
        $this->createSku('C-1', '30.00');
        $this->createSku('ERP-ONLY', '5.00', inShopify: false);
        $this->fakeShopify()->addProduct('orphelin', 'Orphelin', [['sku' => 'SHOP-ONLY', 'price' => '1.00']]);

        $run = $this->dispatcher()->dispatch(new PullShopifyCatalog(pageSize: 2));

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(2, $run->result['pages']);
        $this->assertSame(3, $run->result['mapped']);
        $this->assertSame(1, $run->result['conflicts']);
        $this->assertSame(['SHOP-ONLY'], $run->result['unknown_in_erp']);
        $this->assertSame(['ERP-ONLY'], $run->result['erp_not_in_shopify']);
        $this->assertSame('18.00', Conflict::sole()->shopify_value);
    }

    public function test_open_price_conflict_blocks_price_push_but_not_stock(): void
    {
        $this->createSku('B-1', '20.00', onHand: 4, shopifyPrice: '18.00');
        $this->dispatcher()->dispatch(new PullShopifyCatalog);

        $run = $this->dispatcher()->dispatch(new PushVariantToShopify('B-1'));

        $remote = $this->fakeShopify()->findVariantBySku('B-1');
        $this->assertSame('18.00', $remote->price);
        $this->assertSame(4, $remote->inventoryQuantity);
        $this->assertSame('non poussé (conflit ouvert)', $run->result['price']);
    }

    public function test_resolving_conflict_by_keeping_erp_pushes_erp_price(): void
    {
        $variant = $this->createSku('B-1', '20.00', shopifyPrice: '18.00');
        $this->dispatcher()->dispatch(new PullShopifyCatalog);

        $this->app->make(ConflictResolver::class)->resolve(Conflict::sole(), ConflictResolution::KeepErp, 'admin@uimadsync.test');

        $this->assertSame('20.00', $this->fakeShopify()->findVariantBySku('B-1')->price);
        $this->assertSame(VariantSyncStatus::Synced, $variant->fresh()->sync_status);
        $this->assertSame('admin@uimadsync.test', Conflict::sole()->resolved_by);
    }

    public function test_resolving_conflict_by_accepting_shopify_updates_erp(): void
    {
        $variant = $this->createSku('B-1', '20.00', shopifyPrice: '18.00');
        $this->dispatcher()->dispatch(new PullShopifyCatalog);

        $this->app->make(ConflictResolver::class)->resolve(Conflict::sole(), ConflictResolution::AcceptShopify, 'admin');

        $this->assertSame('18.00', $variant->fresh()->price);
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(ConflictResolver::class)->resolve(Conflict::sole(), ConflictResolution::KeepErp, 'admin');
    }

    public function test_pull_orders_recovers_missed_orders_idempotently(): void
    {
        $this->createSku('A-1', '10.00', onHand: 5);
        $this->fakeShopify()->addOrder(new OrderData('gid://shopify/Order/1', '#1', null, 'EUR', '20.00', 'paid', '2026-10-24T08:00:00Z', [
            ['sku' => 'A-1', 'title' => 'A', 'quantity' => 2, 'unit_price' => '10.00'],
        ]));

        $first = $this->dispatcher()->dispatch(new PullShopifyOrders);
        $second = $this->dispatcher()->dispatch(new PullShopifyOrders);

        $this->assertSame(['#1'], $first->result['created']);
        $this->assertSame([], $second->result['created']);
        $this->assertSame(1, Order::count());
        $this->assertSame(3, StockLevel::sole()->on_hand);
    }

    public function test_every_run_is_journaled_with_payload_and_duration(): void
    {
        $this->createSku('A-1');
        $this->dispatcher()->dispatch(new PushVariantToShopify('A-1'));

        $run = SyncRun::sole();
        $this->assertSame(['sku' => 'A-1'], $run->payload);
        $this->assertSame('erp_to_shopify', $run->direction);
        $this->assertSame(PushVariantToShopify::class, $run->job_class);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }
}
