<?php

namespace App\Domain\Enums;

enum SyncStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';   // tentative échouée, un retry est programmé
    case Dead = 'dead';       // retries épuisés : dead-letter, intervention humaine

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En file',
            self::Running => 'En cours',
            self::Succeeded => 'Réussi',
            self::Failed => 'Échec (retry prévu)',
            self::Dead => 'Dead-letter',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Running => 'info',
            self::Succeeded => 'success',
            self::Failed => 'warning',
            self::Dead => 'danger',
        };
    }

    public function isReplayable(): bool
    {
        return in_array($this, [self::Failed, self::Dead], true);
    }
}
