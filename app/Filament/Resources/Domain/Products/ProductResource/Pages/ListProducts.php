<?php

namespace App\Filament\Resources\Domain\Products\ProductResource\Pages;

use App\Filament\Resources\Domain\Products\ProductResource;
use App\Services\Odoo\OdooSyncService;
use Filament\Forms;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('import_from_odoo')
                ->label('Import from Odoo')
                ->icon('heroicon-m-cloud-arrow-down')
                ->form([
                    Forms\Components\Textarea::make('skus')
                        ->label('SKUs (optional)')
                        ->placeholder("SKU-1001, SKU-1002 or one per line")
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\DatePicker::make('updated_after')
                        ->label('Updated after')
                        ->native(false),
                    Forms\Components\TextInput::make('limit')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->default(100)
                        ->label('Limit'),
                ])
                ->modalHeading('Import products from Odoo')
                ->modalSubmitActionLabel('Import')
                ->action(function (array $data): void {
                    $filters = [];
                    $options = [];

                    $skuInput = trim((string) ($data['skus'] ?? ''));
                    if ($skuInput !== '') {
                        $filters['skus'] = array_values(array_filter(array_map(
                            static fn (string $sku) => trim($sku),
                            preg_split('/[\s,]+/', $skuInput, -1, PREG_SPLIT_NO_EMPTY) ?: []
                        )));
                    }

                    if (! empty($data['updated_after'])) {
                        $filters['updated_after'] = $data['updated_after'];
                    }

                    if (! empty($data['limit'])) {
                        $options['limit'] = (int) $data['limit'];
                    }

                    try {
                        /** @var OdooSyncService $service */
                        $service = app(OdooSyncService::class);
                        $summary = $service->pullProducts($filters, $options);

                        $body = sprintf(
                            'Fetched %d products: %d created, %d updated, %d unchanged.',
                            $summary['fetched'] ?? 0,
                            $summary['created'] ?? 0,
                            $summary['updated'] ?? 0,
                            $summary['unchanged'] ?? 0
                        );

                        Notification::make()
                            ->title('Import complete')
                            ->body($body)
                            ->success()
                            ->send();

                        if (! empty($summary['errors'])) {
                            Notification::make()
                                ->title('Some products failed to import')
                                ->body(collect($summary['errors'])->pluck('message')->take(3)->implode(PHP_EOL))
                                ->warning()
                                ->send();
                        }
                    } catch (\Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Import failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('export')
                ->label('Export CSV')
                ->icon('heroicon-m-arrow-down-tray')
                ->url(route('products.export'))
                ->openUrlInNewTab(),
        ];
    }
}
