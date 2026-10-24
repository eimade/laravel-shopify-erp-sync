<?php

namespace App\Integrations\Erp;

use App\Domain\StockCalculator;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mini-ERP simulé : les tables variants / stock_levels / orders de Uimad Sync.
 */
class DatabaseErpClient implements ErpClient
{
    public function __construct(private readonly StockCalculator $calculator) {}

    public function item(string $sku): ?ErpItem
    {
        $variant = Variant::with('stockLevels')->where('sku', $sku)->first();
        if ($variant === null) {
            return null;
        }

        return new ErpItem($variant->sku, (string) $variant->price, $variant->compare_at_price, $variant->availableQuantity());
    }

    public function updatePrice(string $sku, string $price): void
    {
        $updated = Variant::where('sku', $sku)->update(['price' => $price]);
        if ($updated === 0) {
            throw new InvalidArgumentException("SKU {$sku} inconnu de l'ERP");
        }
    }

    public function recordSale(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->accounting_status === 'exported') {
                return false;
            }

            foreach ($locked->lines as $line) {
                if ($line->variant_id === null) {
                    continue;
                }
                $levels = StockLevel::where('variant_id', $line->variant_id)->lockForUpdate()->get();
                $allocation = $this->calculator->allocate(
                    $levels->pluck('on_hand', 'location')->map(fn ($v) => (int) $v)->all(),
                    $line->quantity
                );
                foreach ($allocation as $location => $qty) {
                    StockLevel::where('variant_id', $line->variant_id)->where('location', $location)->decrement('on_hand', $qty);
                }
            }

            $locked->update(['accounting_status' => 'exported', 'accounting_exported_at' => now()]);

            return true;
        });
    }
}
