<?php

namespace App\Filament\Resources\Domain\Products;

use App\Domain\Products\Product;
use App\Filament\Resources\Domain\Products\ProductResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                        return $query->when(
                            filled($data['value'] ?? null),
                            fn (Builder $q, string $value) => $q->where('sku', 'like', '%'.$value.'%')
                        );
                    })
                    ->indicateUsing(function (array $data): ?string {
                        return filled($data['value'] ?? null)
                            ? 'SKU contains: '.$data['value']
                            : null;
                    }),
                Filter::make('cost_range')
                    ->form([
                        Forms\Components\TextInput::make('min')->numeric()->label('Min cost'),
                        Forms\Components\TextInput::make('max')->numeric()->label('Max cost'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['min'] ?? null, fn (Builder $q, $value) => $q->where('cost_price', '>=', $value))
                            ->when($data['max'] ?? null, fn (Builder $q, $value) => $q->where('cost_price', '<=', $value));
                    }),
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
