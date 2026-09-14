<?php

namespace App\Filament\Resources\Commerce\Orders\Schemas;

use App\Models\Commerce\Order;
use App\Models\Commerce\OrderItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Order')->columns(2)->schema([
                TextEntry::make('uuid')->label('Order ID')->copyable(),
                TextEntry::make('status')->badge(),
                TextEntry::make('placed_at')->dateTime()->placeholder('Not recorded'),
                TextEntry::make('currency'),
                TextEntry::make('subtotal')->money(fn (Order $record): string => $record->currency),
                TextEntry::make('tax_amount')->money(fn (Order $record): string => $record->currency),
                TextEntry::make('shipping_amount')->money(fn (Order $record): string => $record->currency),
                TextEntry::make('discount_amount')->money(fn (Order $record): string => $record->currency),
                TextEntry::make('total_amount')->money(fn (Order $record): string => $record->currency),
            ]),
            Section::make('Local checkout attempt')
                ->description('Completed means the provider response was recorded, not that payment was confirmed. Unknown or submitting attempts require reconciliation.')
                ->schema([
                    // Select only operational summary fields, never the encrypted receipt/result.
                    TextEntry::make('checkout_status')->label('Status')
                        ->state(fn (Order $record): ?string => $record->checkoutAttempt()->value('status'))
                        ->placeholder('No local checkout attempt')->badge(),
                    TextEntry::make('checkout_context')->label('Checkout context ID')
                        ->state(fn (Order $record): ?string => $record->checkoutAttempt()->value('uuid'))->placeholder('—'),
                    TextEntry::make('checkout_provider_instance')->label('Recorded provider instance')
                        ->state(fn (Order $record): ?string => $record->checkoutAttempt()
                            ->join('provider_instances', 'provider_instances.id', '=', 'checkout_attempts.provider_instance_id')
                            ->value('provider_instances.key'))
                        ->placeholder('Not bound'),
                    TextEntry::make('checkout_receipt_available')->label('Provider receipt available')
                        ->state(fn (Order $record): string => $record->checkoutAttempt()
                            ->whereNotNull('provider_receipt')->exists() ? 'Yes' : 'No'),
                    TextEntry::make('checkout_receipt_received')->label('Receipt received')
                        ->state(fn (Order $record): mixed => $record->checkoutAttempt()->value('receipt_received_at'))
                        ->dateTime()->placeholder('Not recorded'),
                    TextEntry::make('checkout_submitted')->label('Submitted')
                        ->state(fn (Order $record): mixed => $record->checkoutAttempt()->value('submitted_at'))->dateTime()->placeholder('—'),
                    TextEntry::make('checkout_completed')->label('Completed')
                        ->state(fn (Order $record): mixed => $record->checkoutAttempt()->value('completed_at'))->dateTime()->placeholder('—'),
                ]),
            Section::make('Line items')->schema([
                RepeatableEntry::make('items')->schema([
                    TextEntry::make('name'),
                    TextEntry::make('sku')->placeholder('—'),
                    TextEntry::make('quantity'),
                    TextEntry::make('unit_price')->money(fn (OrderItem $record): string => $record->order->currency),
                    TextEntry::make('line_total')->money(fn (OrderItem $record): string => $record->order->currency),
                ])->columns(3),
            ]),
            Section::make('Shipments')->schema([
                RepeatableEntry::make('shipments')->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('carrier')->placeholder('—'),
                    TextEntry::make('tracking_number')->placeholder('—'),
                    TextEntry::make('shipped_at')->dateTime()->placeholder('—'),
                    TextEntry::make('delivered_at')->dateTime()->placeholder('—'),
                ])->columns(3),
            ]),
        ]);
    }
}
