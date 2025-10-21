<?php

namespace App\Filament\Resources\Domain\Products;

use App\Domain\Products\Product;
use App\Filament\Resources\Domain\Products\ProductResource\Pages;
use App\Services\Odoo\OdooSyncService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Catalog';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Product Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->maxLength(100)
                            ->unique(table: Product::class, ignoreRecord: true),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('cost_price')
                            ->label('Cost Price')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->reactive()
                            ->suffix(fn () => ' '.config('services.odoo.currency', 'USD'))
                            ->rule('decimal:0,2'),
                        Forms\Components\TextInput::make('markup_percent')
                            ->label('Markup %')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(999.99)
                            ->suffix('%')
                            ->rule('decimal:0,2')
                            ->reactive(),
                        Forms\Components\TextInput::make('currency')
                            ->maxLength(3)
                            ->default(fn () => config('services.odoo.currency', 'USD'))
                            ->required()
                            ->datalist(['USD', 'EUR', 'GBP', 'TRY']),
                        Forms\Components\Placeholder::make('sale_price_preview')
                            ->label('Sale Price')
                            ->content(function (callable $get) {
                                $cost = (float) ($get('cost_price') ?? 0);
                                $markup = (float) ($get('markup_percent') ?? 0);
                                $sale = round($cost * (1 + ($markup / 100)), 2);

                                return number_format($sale, 2).' '.strtoupper($get('currency') ?? config('services.odoo.currency', 'USD'));
                            })
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Sync Status')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('origin_system')
                            ->label('Source')
                            ->content(fn (?Product $record) => $record?->origin_system ? ucfirst($record->origin_system) : 'N/A'),
                        Forms\Components\Placeholder::make('last_sync_status')
                            ->label('Status')
                            ->content(fn (?Product $record) => $record?->last_sync_status ? ucfirst($record->last_sync_status) : 'Never synced'),
                        Forms\Components\Placeholder::make('last_synced_at')
                            ->label('Last Synced At')
                            ->content(fn (?Product $record) => $record?->last_synced_at?->format('Y-m-d H:i') ?? 'N/A'),
                        Forms\Components\Placeholder::make('last_sync_message')
                            ->label('Last Message')
                            ->content(fn (?Product $record) => $record?->last_sync_message ?? 'N/A')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (?Product $record) => $record !== null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('cost_price')
                    ->label('Cost')
                    ->sortable()
                    ->formatStateUsing(fn (Product $record) => number_format($record->cost_price, 2))
                    ->suffix(fn (Product $record) => ' '.$record->currency),
                Tables\Columns\TextColumn::make('markup_percent')
                    ->label('Markup %')
                    ->sortable()
                    ->formatStateUsing(fn (Product $record) => number_format($record->markup_percent, 2))
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Sale Price')
                    ->sortable()
                    ->formatStateUsing(fn (Product $record) => number_format($record->sale_price, 2))
                    ->suffix(fn (Product $record) => ' '.$record->currency),
                Tables\Columns\BadgeColumn::make('origin_system')
                    ->label('Source')
                    ->color(fn (?string $state) => match ($state) {
                        'odoo' => 'success',
                        'local' => 'warning',
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : 'Unknown')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('last_sync_status')
                    ->label('Sync Status')
                    ->color(fn (?string $state) => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        'processing' => 'info',
                        'pending' => 'warning',
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : 'Never')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_sync_message')
                    ->label('Last Message')
                    ->wrap()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Filter::make('sku')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('SKU contains')
                            ->placeholder('e.g. SKU-100')
                            ->maxLength(100),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['value'] ?? ''));

                        return $query->when(
                            $value !== '',
                            fn (Builder $q) => $q->where('sku', 'like', '%'.$value.'%')
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $value = trim((string) ($data['value'] ?? ''));

                        return $value !== ''
                            ? 'SKU contains: '.$value
                            : null;
                    }),
                Filter::make('cost_range')
                    ->form([
                        Forms\Components\TextInput::make('min')->numeric()->label('Min cost'),
                        Forms\Components\TextInput::make('max')->numeric()->label('Max cost'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $min = $data['min'] ?? null;
                        $max = $data['max'] ?? null;

                        return $query
                            ->when($min !== null && $min !== '', fn (Builder $q) => $q->where('cost_price', '>=', (float) $min))
                            ->when($max !== null && $max !== '', fn (Builder $q) => $q->where('cost_price', '<=', (float) $max));
                    }),
                SelectFilter::make('origin_system')
                    ->label('Source')
                    ->options([
                        'local' => 'Local',
                        'odoo' => 'Odoo',
                    ]),
                SelectFilter::make('last_sync_status')
                    ->label('Sync Status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'success' => 'Success',
                        'failed' => 'Failed',
                        'never' => 'Never',
                    ]),
                Filter::make('needs_attention')
                    ->label('Needs attention')
                    ->form([
                        Forms\Components\Toggle::make('only')
                            ->label('Show products needing attention'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $only = (bool) ($data['only'] ?? false);

                        return $query->when(
                            $only,
                            fn (Builder $q) => $q->whereIn('last_sync_status', ['failed', 'pending'])
                        );
                    })
                    ->indicateUsing(fn (array $data): ?string => ! empty($data['only']) ? 'Needs attention' : null),
                Filter::make('updated_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('updated_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('updated_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('push_to_odoo')
                        ->label('Push to Odoo')
                        ->icon('heroicon-m-cloud-arrow-up')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            if ($records->isEmpty()) {
                                return;
                            }

                            /** @var OdooSyncService $service */
                            $service = app(OdooSyncService::class);
                            $summary = $service->pushProducts($records);

                            $body = sprintf(
                                'Processed %d products — %d success, %d failed.',
                                $summary['total'],
                                $summary['success'],
                                $summary['failed']
                            );

                            $notification = Notification::make()
                                ->title('Odoo sync complete')
                                ->body($body);

                            $summary['failed'] > 0
                                ? $notification->warning()
                                : $notification->success();

                            $notification->send();
                        }),
                    Tables\Actions\BulkAction::make('increase_cost')
                        ->label('Increase cost %')
                        ->icon('heroicon-m-arrow-up-right')
                        ->form([
                            Forms\Components\TextInput::make('percentage')
                                ->label('Increase by %')
                                ->numeric()
                                ->required()
                                ->minValue(0.1)
                                ->rule('decimal:0,2'),
                        ])
                        ->requiresConfirmation()
                        ->action(fn (Collection $records, array $data) => self::applyCostChange($records, (float) $data['percentage'])),
                    Tables\Actions\BulkAction::make('decrease_cost')
                        ->label('Decrease cost %')
                        ->icon('heroicon-m-arrow-down-left')
                        ->color('danger')
                        ->form([
                            Forms\Components\TextInput::make('percentage')
                                ->label('Decrease by %')
                                ->numeric()
                                ->required()
                                ->minValue(0.1)
                                ->rule('decimal:0,2'),
                        ])
                        ->requiresConfirmation()
                        ->action(fn (Collection $records, array $data) => self::applyCostChange($records, -(float) $data['percentage'])),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    protected static function applyCostChange(Collection $records, float $percentage): void
    {
        $multiplier = 1 + ($percentage / 100);

        $records->each(function (Product $product) use ($multiplier): void {
            $newCost = round($product->cost_price * $multiplier, 2);
            $product->cost_price = $newCost > 0 ? $newCost : 0;
            $product->save();
        });
    }
}
