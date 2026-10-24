<?php

namespace App\Services;

use App\Integrations\Erp\ErpClient;
use App\Integrations\Shopify\Data\OrderData;
use App\Models\Order;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remonte une commande Shopify dans l'ERP : enregistrement, décrément de stock, comptabilisation.
 * Idempotent : une commande déjà connue n'est ni dupliquée ni recomptabilisée.
 */
class OrderImporter
{
    public function __construct(private readonly ErpClient $erp) {}

    /**
     * @return array{order_id: int, created: bool, recorded_in_erp: bool, unknown_skus: list<string>}
     */
    public function import(OrderData $data): array
    {
        $unknown = [];

        [$order, $created] = DB::transaction(function () use ($data, &$unknown) {
            $existing = Order::where('shopify_order_id', $data->id)->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            $order = Order::create([
                'shopify_order_id' => $data->id,
                'name' => $data->name,
                'email' => $data->email,
                'currency' => $data->currency,
                'total_price' => $data->totalPrice,
                'financial_status' => $data->financialStatus,
                'placed_at' => $data->placedAt ? Carbon::parse($data->placedAt) : now(),
            ]);

            foreach ($data->lines as $line) {
                $sku = SkuMapper::normalize($line['sku']);
                $variantId = $sku ? Variant::where('sku', $sku)->value('id') : null;
                if ($sku !== null && $variantId === null) {
                    $unknown[] = $sku;
                }
                $order->lines()->create([
                    'variant_id' => $variantId,
                    'sku' => $sku,
                    'title' => $line['title'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);
            }

            return [$order, true];
        });

        $recorded = $this->erp->recordSale($order);

        return [
            'order_id' => $order->id,
            'created' => $created,
            'recorded_in_erp' => $recorded,
            'unknown_skus' => $unknown,
        ];
    }
}
