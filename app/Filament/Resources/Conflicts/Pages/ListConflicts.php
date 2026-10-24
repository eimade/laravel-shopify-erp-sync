<?php

namespace App\Filament\Resources\Conflicts\Pages;

use App\Filament\Resources\Conflicts\ConflictResource;
use Filament\Resources\Pages\ListRecords;

class ListConflicts extends ListRecords
{
    protected static string $resource = ConflictResource::class;
}
