<?php

namespace App\Domain\Enums;

enum VariantSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Unmapped = 'unmapped';
    case Conflict = 'conflict';
    case Error = 'error';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Synced => 'success',
            self::Unmapped, self::Conflict => 'warning',
            self::Error => 'danger',
        };
    }
}
