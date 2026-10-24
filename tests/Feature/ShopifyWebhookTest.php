<?php

namespace Tests\Feature;

use App\Domain\Enums\ConflictStatus;
use App\Domain\Enums\SyncStatus;
use App\Domain\Enums\VariantSyncStatus;
use App\Http\Middleware\VerifyShopifyWebhook;
use App\Jobs\ImportShopifyOrder;
use App\Jobs\PullShopifyCatalog;
use App\Models\Conflict;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\SyncRun;
use App\Models\WebhookReceipt;
use App\Services\SyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShopifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendWebhook(string $topic, array $payload, string $webhookId = 'wh-1', ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($payload);
        $signature ??= VerifyShopifyWebhook::sign($body, 'test-webhook-secret');

        return $this->call('POST', '/api/webhooks/shopify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'uimadsync-demo.myshopify.com',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $signature,
        ], $body);
    }

    /** @return array<string, mixed> */
    private function orderPayload(string $sku = 'ROBE-LIN-S', int $qty = 2): array
    {
        return [
            'id' => 450789469,
            'name' => '#1001',
            'email' => 'cliente@example.com',
            'currency' => 'EUR',
            'total_price' => '159.80',
            'financial_status' => 'paid',
            'created_at' => '2026-10-24T10:00:00+02:00',
            'line_items' => [['sku' => $sku, 'title' => 'Robe en lin', 'quantity' => $qty, 'price' => '79.90']],
        ];
    }

    public function test_valid_order_webhook_creates_order_and_decrements_erp_stock(): void
    {
        $variant = $this->createSku('ROBE-LIN-S', '79.90', onHand: 10);

        $response = $this->sendWebhook('orders/create', $this->orderPayload());

        $response->assertStatus(202)->assertJsonPath('status', 'accepted');
        $order = Order::with('lines')->sole();
        $this->assertSame('gid://shopify/Order/450789469', $order->shopify_order_id);
        $this->assertSame('exported', $order->accounting_status);
        $this->assertSame($variant->id, $order->lines->first()->variant_id);
        $this->assertSame(8, StockLevel::where('variant_id', $variant->id)->value('on_hand'));

        $run = SyncRun::sole();
        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame($run->id, WebhookReceipt::sole()->sync_run_id);
        $this->assertNotNull($run->duration_ms);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->createSku('ROBE-LIN-S');

        $this->sendWebhook('orders/create', $this->orderPayload(), signature: base64_encode('forged'))
            ->assertStatus(401);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, WebhookReceipt::count());
    }

    public function test_tampered_body_is_rejected(): void
    {
        $signature = VerifyShopifyWebhook::sign((string) json_encode($this->orderPayload(qty: 1)), 'test-webhook-secret');

        $this->sendWebhook('orders/create', $this->orderPayload(qty: 50), signature: $signature)->assertStatus(401);
    }

    public function test_webhooks_are_refused_when_secret_is_not_configured(): void
    {
        config(['uimadsync.shopify.webhook_secret' => '']);

        $this->sendWebhook('orders/create', $this->orderPayload(), signature: 'x')->assertStatus(503);
    }

    public function test_same_webhook_delivered_twice_is_processed_once(): void
    {
        $variant = $this->createSku('ROBE-LIN-S', '79.90', onHand: 10);

        $this->sendWebhook('orders/create', $this->orderPayload(), 'wh-dup')->assertStatus(202);
        $this->sendWebhook('orders/create', $this->orderPayload(), 'wh-dup')
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, SyncRun::count());
        $this->assertSame(8, StockLevel::where('variant_id', $variant->id)->value('on_hand'));
    }

    public function test_same_order_with_different_webhook_ids_is_not_recorded_twice(): void
    {
        $variant = $this->createSku('ROBE-LIN-S', '79.90', onHand: 10);

        $this->sendWebhook('orders/create', $this->orderPayload(), 'wh-a')->assertStatus(202);
        $this->sendWebhook('orders/create', $this->orderPayload(), 'wh-b')->assertStatus(202);

        $this->assertSame(1, Order::count());
        $this->assertSame(8, StockLevel::where('variant_id', $variant->id)->value('on_hand'));
        $this->assertFalse(SyncRun::latest('id')->first()->result['created']);
    }

    public function test_unknown_topic_and_invalid_payload_are_rejected(): void
    {
        $this->sendWebhook('customers/create', ['id' => 1])->assertStatus(422);
        $this->sendWebhook('orders/create', ['id' => 1], 'wh-2')->assertStatus(422);
        $this->assertSame(0, WebhookReceipt::count());
    }

    public function test_product_update_with_manual_price_change_opens_a_conflict(): void
    {
        $variant = $this->createSku('SERUM-VITC-30', '34.50', inShopify: true);
        $this->app->make(SyncDispatcher::class)->dispatch(new PullShopifyCatalog);
        $variant->refresh();

        $this->sendWebhook('products/update', [
            'id' => 1,
            'admin_graphql_api_id' => $variant->product->fresh()->shopify_product_id,
            'handle' => $variant->product->handle,
            'variants' => [[
                'id' => 2,
                'admin_graphql_api_id' => $variant->shopify_variant_id,
                'sku' => 'SERUM-VITC-30',
                'price' => '29.90',
                'inventory_item_id' => 3,
            ]],
        ])->assertStatus(202);

        $conflict = Conflict::sole();
        $this->assertSame(ConflictStatus::Open, $conflict->status);
        $this->assertSame('34.50', $conflict->erp_value);
        $this->assertSame('29.90', $conflict->shopify_value);
        $this->assertSame(VariantSyncStatus::Conflict, $variant->fresh()->sync_status);
    }

    public function test_webhook_is_acknowledged_quickly_and_processed_in_queue(): void
    {
        Queue::fake();
        $this->createSku('ROBE-LIN-S');

        $this->sendWebhook('orders/create', $this->orderPayload())->assertStatus(202);

        Queue::assertPushed(ImportShopifyOrder::class);
        $this->assertSame(SyncStatus::Queued, SyncRun::sole()->status);
        $this->assertSame(0, Order::count());
    }
}
