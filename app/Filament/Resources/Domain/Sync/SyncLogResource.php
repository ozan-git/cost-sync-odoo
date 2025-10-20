<?php

namespace App\Filament\Resources\Domain\Sync;

use App\Domain\Sync\SyncLog;
use App\Filament\Resources\Domain\Sync\SyncLogResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SyncLogResource extends Resource
{
    protected static ?string $model = SyncLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';

    protected static ?string $navigationGroup = 'Sync';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Summary')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('sku')->disabled(),
                        Forms\Components\TextInput::make('status')->disabled(),
                        Forms\Components\TextInput::make('message')
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\DateTimePicker::make('created_at')
                            ->label('Created at')
                            ->disabled(),
                    ]),
                Forms\Components\Section::make('Payload & Response')
                    ->schema([
                        Forms\Components\Textarea::make('payload')
                            ->label('Payload')
                            ->rows(6)
                            ->dehydrated(false)
                            ->disabled()
                            ->formatStateUsing(fn ($state) => static::formatJsonState($state, pretty: true)),
                        Forms\Components\Textarea::make('response')
                            ->label('Response')
                            ->rows(6)
                            ->dehydrated(false)
                            ->disabled()
                            ->formatStateUsing(fn ($state) => static::formatJsonState($state, pretty: true)),
                    ])
                    ->columns(1),
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
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => $state === 'success' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('message')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('payload')
                    ->label('Payload')
                    ->formatStateUsing(fn ($state) => static::formatJsonState($state))
                    ->copyable()
                    ->toggleable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('response')
                    ->label('Response')
                    ->formatStateUsing(fn ($state) => static::formatJsonState($state))
                    ->copyable()
                    ->toggleable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Synced At')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('sku')
                    ->form([
                        Forms\Components\TextInput::make('value')
                            ->label('SKU contains')
                            ->placeholder('e.g. SKU-100')
                            ->maxLength(100),
                    ])
                    ->query(function ($query, array $data) {
                        return $query->when(
                            filled($data['value'] ?? null),
                            fn ($q, string $value) => $q->where('sku', 'like', '%'.$value.'%')
                        );
                    })
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                        ? 'SKU contains: '.$data['value']
                        : null),
                SelectFilter::make('status')
                    ->options([
                        'success' => 'Success',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncLogs::route('/'),
            'view' => Pages\ViewSyncLog::route('/{record}'),
        ];
    }

    protected static function formatJsonState(mixed $state, bool $pretty = false): string
    {
        if ($state === null || $state === '') {
            return '';
        }

        if (is_array($state)) {
            return json_encode($state, $pretty ? JSON_PRETTY_PRINT : 0) ?: '';
        }

        if (is_string($state)) {
            $decoded = json_decode($state, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, $pretty ? JSON_PRETTY_PRINT : 0) ?: '';
            }

            return $state;
        }

        return json_encode($state, $pretty ? JSON_PRETTY_PRINT : 0) ?: '';
    }
}
