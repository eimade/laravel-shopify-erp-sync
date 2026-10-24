<?php

namespace Tests\Unit;

use App\Domain\ShopifyGid;
use App\Http\Middleware\VerifyShopifyWebhook;
use App\Integrations\Shopify\Data\OrderData;
use App\Services\SkuMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MappingTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function skus(): array
    {
        return [
            'déjà normalisé' => ['ROBE-LIN-S', 'ROBE-LIN-S'],
            'minuscules' => ['robe-lin-s', 'ROBE-LIN-S'],
            'espaces' => ['  ROBE - LIN-S ', 'ROBE-LIN-S'],
            'vide' => ['   ', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('skus')]
    public function test_sku_normalization(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, SkuMapper::normalize($input));
    }

    public function test_price_comparison_is_done_in_cents(): void
    {
        $this->assertFalse(SkuMapper::pricesDiffer('34.50', '34.5'));
        $this->assertFalse(SkuMapper::pricesDiffer('34.50', 34.500001));
        $this->assertTrue(SkuMapper::pricesDiffer('34.50', '29.90'));
    }

    public function test_shopify_gid_conversion(): void
    {
        $this->assertSame('gid://shopify/ProductVariant/42', ShopifyGid::make('ProductVariant', 42));
        $this->assertSame('gid://shopify/ProductVariant/42', ShopifyGid::make('ProductVariant', 'gid://shopify/ProductVariant/42'));
        $this->assertSame('42', ShopifyGid::numericId('gid://shopify/ProductVariant/42'));
    }

    public function test_order_webhook_payload_is_normalized(): void
    {
        $order = OrderData::fromWebhook([
            'id' => 820982911946154508,
            'name' => '#1001',
            'currency' => 'EUR',
            'total_price' => '159.80',
            'line_items' => [['sku' => 'ROBE-LIN-S', 'title' => 'Robe', 'quantity' => 2, 'price' => '79.90']],
        ]);

        $this->assertSame('gid://shopify/Order/820982911946154508', $order->id);
        $this->assertSame(2, $order->lines[0]['quantity']);
        $this->assertEquals($order, OrderData::fromArray($order->toArray()));
    }

    public function test_incomplete_order_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrderData::fromWebhook(['id' => 1]);
    }

    public function test_hmac_signature_matches_shopify_algorithm(): void
    {
        $body = '{"id":1}';
        $expected = base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $this->assertSame($expected, VerifyShopifyWebhook::sign($body, 'secret'));
        $this->assertTrue(VerifyShopifyWebhook::isValid($body, $expected, 'secret'));
        $this->assertFalse(VerifyShopifyWebhook::isValid($body.' ', $expected, 'secret'));
        $this->assertFalse(VerifyShopifyWebhook::isValid($body, '', 'secret'));
    }
}
