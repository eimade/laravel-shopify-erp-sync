<?php

namespace App\Models;

use App\Domain\Enums\SyncStatus;
use App\Domain\Enums\SyncType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une ligne du journal de synchronisation (une exécution de job, avec ses tentatives).
 *
 * @property int $id
 * @property SyncType $type
 * @property string $direction
 * @property string|null $subject
 * @property SyncStatus $status
 * @property int $attempts
 * @property string $job_class
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property int|null $duration_ms
 * @property int|null $replayed_from_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class SyncRun extends Model
{
    protected $fillable = [
        'type', 'direction', 'subject', 'status', 'attempts', 'job_class', 'payload', 'result',
        'error', 'duration_ms', 'replayed_from_id', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SyncType::class,
            'status' => SyncStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'attempts' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SyncRun, $this> */
    public function replayedFrom(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'replayed_from_id');
    }
}
