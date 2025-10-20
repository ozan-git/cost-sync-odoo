<?php

namespace App\Filament\Resources\Domain\Products\ProductResource\Pages;

use App\Filament\Resources\Domain\Products\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-m-arrow-down-tray')
                ->url(route('products.export'))
                ->openUrlInNewTab(),
        ];
    }
}
