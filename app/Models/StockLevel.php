<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock ERP d'une variante dans un entrepôt.
 *
 * @property int $id
 * @property int $variant_id
 * @property string $location
 * @property int $on_hand
 * @property int $reserved
 * @property int $safety_stock
 */
class StockLevel extends Model
{
    protected $fillable = ['variant_id', 'location', 'on_hand', 'reserved', 'safety_stock'];

    protected function casts(): array
    {
        return ['on_hand' => 'integer', 'reserved' => 'integer', 'safety_stock' => 'integer'];
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
