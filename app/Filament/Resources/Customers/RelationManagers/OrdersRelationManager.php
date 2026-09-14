<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Commerce\Orders\OrderResource;
use App\Models\Commerce\Order;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can('view', $ownerRecord) ?? false)
            && (auth()->user()?->can('viewAny', Order::class) ?? false)
            && (auth()->user()?->can('view', new Order) ?? false);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);
        parent::mount();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): void {
                // Recheck on every Livewire request, not just initial tab visibility.
                abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);
            })
            ->columns([
                TextColumn::make('uuid')->label('Order ID')->copyable(),
                TextColumn::make('placed_at')->label('Placed')->dateTime()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('total_amount')->money(fn (Order $record): string => $record->currency),
                TextColumn::make('currency'),
                TextColumn::make('items_count')->counts('items')->label('Line items'),
            ])
            ->defaultSort('placed_at', 'desc')
            ->recordActions([
                Action::make('viewOrder')->label('View order')
                    ->visible(fn (Order $record): bool => auth()->user()?->can('view', $record) ?? false)
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
