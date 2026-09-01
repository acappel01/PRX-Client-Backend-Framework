<?php

namespace App\Filament\Resources\Catalog\ProductClasses\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ProductClassForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Product class')
                    ->vertical()
                    ->persistTabInQueryString('product-class-tab')
                    ->columnSpanFull()
                    ->tabs([

                        // ── Details ───────────────────────────────────
                        Tab::make('Details')
                            ->icon(Heroicon::DocumentText)
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->hintIcon(Heroicon::InformationCircle, 'Top-level product grouping, e.g. Peptides or Hormones. Shown in admin filters and frontend navigation.')
                                    ->afterStateUpdated(function (string $operation, ?string $state, callable $set, callable $get): void {
                                        if ($operation === 'create' && blank($get('slug')) && filled($state)) {
                                            $set('slug', Str::slug($state));
                                        }
                                    }),
                                TextInput::make('slug')
                                    ->maxLength(255)
                                    ->alphaDash()
                                    ->helperText('Leave blank to auto-generate from the name.')
                                    ->hintIcon(Heroicon::InformationCircle, 'URL-friendly identifier used in API responses and frontend routes.'),
                                TextInput::make('short_name')
                                    ->maxLength(64)
                                    ->hintIcon(Heroicon::InformationCircle, 'Compact label used where space is tight, e.g. badges and dropdowns.'),
                                TextInput::make('icon')
                                    ->maxLength(64)
                                    ->hintIcon(Heroicon::InformationCircle, 'Optional icon key the frontend maps to its icon set, e.g. beaker or capsule.'),
                                Textarea::make('description')
                                    ->rows(3)
                                    ->columnSpanFull(),
                                TextInput::make('position')
                                    ->numeric()
                                    ->default(0)
                                    ->hintIcon(Heroicon::InformationCircle, 'Controls display order in lists. Lower numbers appear first.'),
                                Toggle::make('is_active')
                                    ->default(true),
                            ]),

                        // ── Integrations ──────────────────────────────
                        Tab::make('Integrations')
                            ->icon(Heroicon::PuzzlePiece)
                            ->columns(2)
                            ->schema([
                                TextInput::make('provider_product_class_id')
                                    ->label('Provider product class ID')
                                    ->maxLength(36)
                                    ->hintIcon(Heroicon::InformationCircle, 'UUID of the matching product class on the provider side.')
                                    ->helperText('Optional mapping to the fulfillment provider (e.g. PrescribeRx) so synced products reuse this row. Leave blank for non-provider vocabulary.'),
                                TextInput::make('provider_product_class_slug')
                                    ->label('Provider product class slug')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, "The provider's own slug for this class, e.g. \"glp-1s-gips\".")
                                    ->helperText('Used when no ID is set. Worth filling in even when the ID is: an ID belongs to one provider environment, so a mapping captured against sandbox silently resolves to nothing against production — a slug usually survives the switch.'),
                            ]),
                    ]),
            ]);
    }
}
