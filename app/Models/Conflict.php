<?php

namespace App\Models;

use App\Domain\Enums\ConflictResolution;
use App\Domain\Enums\ConflictStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Écart détecté entre la valeur ERP (source de vérité) et la valeur Shopify.
 *
 * @property int $id
 * @property int $variant_id
 * @property string $field
 * @property string|null $erp_value
 * @property string|null $shopify_value
 * @property string $source
 * @property ConflictStatus $status
 * @property ConflictResolution|null $resolution
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property-read Variant $variant
 */
class Conflict extends Model
{
    protected $fillable = [
        'variant_id', 'field', 'erp_value', 'shopify_value', 'source', 'status',
        'resolution', 'resolved_by', 'resolved_at',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConflictStatus::class,
            'resolution' => ConflictResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Variant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
