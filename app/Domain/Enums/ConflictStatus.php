<?php

namespace App\Domain\Enums;

enum ConflictStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
