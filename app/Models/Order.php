<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Commande Shopify remontée dans l'ERP.
 *
 * @property int $id
 * @property string $shopify_order_id
 * @property string $name
 * @property string|null $email
 * @property string $currency
 * @property string $total_price
 * @property string|null $financial_status
 * @property Carbon|null $placed_at
 * @property string $accounting_status
 * @property Carbon|null $accounting_exported_at
 * @property-read Collection<int, OrderLine> $lines
 */
class Order extends Model
{
    protected $fillable = [
        'shopify_order_id', 'name', 'email', 'currency', 'total_price', 'financial_status',
        'placed_at', 'accounting_status', 'accounting_exported_at',
    ];

    protected $attributes = [
        'accounting_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
            'placed_at' => 'datetime',
            'accounting_exported_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}
