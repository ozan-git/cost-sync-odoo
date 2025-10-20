<?php

namespace App\Filament\Resources\Domain\Sync\SyncLogResource\Pages;

use App\Filament\Resources\Domain\Sync\SyncLogResource;
use Filament\Resources\Pages\ListRecords;

class ListSyncLogs extends ListRecords
{
    protected static string $resource = SyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
