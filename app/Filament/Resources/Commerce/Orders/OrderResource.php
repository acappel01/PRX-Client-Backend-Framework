<?php

namespace App\Filament\Resources\Commerce\Orders;

use App\Filament\Resources\Commerce\Orders\Pages\EditOrder;
use App\Filament\Resources\Commerce\Orders\Pages\ListOrders;
use App\Filament\Resources\Commerce\Orders\Pages\ViewOrder;
use App\Filament\Resources\Commerce\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Commerce\Orders\RelationManagers\ShipmentsRelationManager;
use App\Filament\Resources\Commerce\Orders\Schemas\OrderForm;
use App\Filament\Resources\Commerce\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Commerce\Orders\Tables\OrdersTable;
use App\Models\Commerce\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'prescribe_rx_order_number';

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            ShipmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
