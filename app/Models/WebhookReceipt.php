<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Webhook Shopify reçu. L'unicité de webhook_id garantit l'idempotence.
 *
 * @property int $id
 * @property string $webhook_id
 * @property string $topic
 * @property string|null $shop_domain
 * @property array<string, mixed> $payload
 * @property int|null $sync_run_id
 */
class WebhookReceipt extends Model
{
    protected $fillable = ['webhook_id', 'topic', 'shop_domain', 'payload', 'sync_run_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<SyncRun, $this> */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }
}
