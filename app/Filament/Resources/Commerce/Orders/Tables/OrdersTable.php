<?php

namespace App\Filament\Resources\Commerce\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Resources\Commerce\Orders\OrderResource;
use App\Models\Commerce\Order;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')->label('Order ID')->searchable()->copyable(),
                TextColumn::make('placed_at')
                    ->label('Placed')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('prescribe_rx_order_number')
                    ->label('Order #')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (OrderStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('customer.uuid')
                    ->label('Customer ID')
                    ->placeholder('Unassigned')
                    ->searchable(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('shipments_count')
                    ->label('Shipments')
                    ->counts('shipments')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->money(fn (Order $record): string => $record->currency)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('shipped_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('delivered_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordUrl(fn (Order $record): ?string => ! $record->trashed() && OrderResource::canView($record) ? OrderResource::getUrl('view', ['record' => $record]) : null)
            ->defaultSort('placed_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make()->visible(fn (Order $record): bool => ! $record->trashed()),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
