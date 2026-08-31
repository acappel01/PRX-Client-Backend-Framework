<?php

namespace App\Filament\Resources\Catalog\Packages\Schemas;

use App\Enums\CatalogStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\Tag;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Package')
                    ->vertical()
                    ->persistTabInQueryString('package-tab')
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
                                    ->hintIcon(Heroicon::InformationCircle, 'Display name shown on the storefront and in admin listings.')
                                    ->afterStateUpdated(function (string $operation, ?string $state, callable $set, callable $get): void {
                                        if ($operation === 'create' && blank($get('slug')) && filled($state)) {
                                            $set('slug', Str::slug($state));
                                        }
                                    }),
                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->alphaDash()
                                    ->hintIcon(Heroicon::InformationCircle, 'URL-friendly identifier. Auto-generated; change with caution — breaks existing links.'),
                                TextInput::make('subtitle')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, 'Short sub-headline shown below the package name on listing cards.')
                                    ->columnSpanFull(),
                                Textarea::make('short_description')
                                    ->maxLength(2000)
                                    ->rows(3)
                                    ->hintIcon(Heroicon::InformationCircle, 'Brief description used in listing cards and API summary responses.')
                                    ->columnSpanFull(),
                                Textarea::make('description')
                                    ->rows(8)
                                    ->hintIcon(Heroicon::InformationCircle, 'Full rich-text package description shown on the detail page.')
                                    ->columnSpanFull(),

                                Section::make('Products in this package')
                                    ->columnSpanFull()
                                    ->components([
                                        Select::make('products')
                                            ->label('Products')
                                            ->multiple()
                                            ->relationship('products', 'name')
                                            ->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id'))
                                            ->preload()
                                            ->hintIcon(Heroicon::InformationCircle, 'Individual products bundled into this package. Used for display and provider mapping.')
                                            ->searchable()
                                            ->helperText('Products bundled into this package.'),
                                    ]),
                            ]),

                        // ── Pricing & availability ────────────────────
                        Tab::make('Pricing & availability')
                            ->icon(Heroicon::CurrencyDollar)
                            ->columns(2)
                            ->schema([
                                Select::make('status')
                                    ->options(CatalogStatus::class)
                                    ->default(CatalogStatus::Draft->value)
                                    ->required()
                                    ->hintIcon(Heroicon::InformationCircle, 'Draft and Pending packages are not visible on the public site.')
                                    ->native(false),

                                Select::make('tier')
                                    ->label('Kind')
                                    ->options([
                                        'protocol' => 'Protocol — a few peptides designed to run together',
                                        'stack' => 'Stack — the larger, complete system',
                                    ])
                                    ->native(false)
                                    ->placeholder('Unclassified')
                                    ->helperText('The intake quiz asks "where would you like to start" and shows a live price range for protocols and for stacks. A package left unclassified appears in neither range.')
                                    ->hintIcon(Heroicon::InformationCircle, 'Free-form on purpose — these two values are Atlas\'s. Another deployment on this backend can use its own.'),

                                Section::make('Pricing')
                                    ->description('Display pricing. Used as transaction price when local checkout is configured; otherwise PRX is the source of truth.')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('retail_price')
                                            ->numeric()
                                            ->prefix('$')
                                            ->step(0.01)
                                            ->hintIcon(Heroicon::InformationCircle, 'Full retail price in dollars. Shown as the crossed-out price when a sale price is set.'),
                                        TextInput::make('sale_price')
                                            ->numeric()
                                            ->prefix('$')
                                            ->step(0.01)
                                            ->hintIcon(Heroicon::InformationCircle, 'Active sale price. If set, this is the price displayed to customers.'),
                                        // NORMALLY BLANK, AND THE HINT HAS TO SAY SO. This
                                        // price is the one-time cost of the bundle, and every
                                        // listing card leads with it — so a per-period suffix
                                        // here makes a card read "$399.00/mo" for a purchase
                                        // the cart books once. Nothing validates the value
                                        // (it is free text with no cadence column behind it),
                                        // so the form's own example is the only guard, and it
                                        // used to suggest "/mo". Four packages carried that
                                        // mistake. Cadence suffixes belong on plans, where
                                        // BillingPeriod fills them in.
                                        TextInput::make('price_suffix')
                                            ->maxLength(32)
                                            ->placeholder('usually blank — e.g. /ea')
                                            ->hintIcon(Heroicon::InformationCircle, 'Usually leave this BLANK. The package price is a one-time price for the bundle and is what listing cards show, so a per-period suffix like "/mo" makes a card advertise a subscription for a purchase billed once. Use it only for a genuine per-unit price such as "/ea". Monthly and prepaid wording belongs on the package\'s Plans.'),
                                        TextInput::make('cost')
                                            ->numeric()
                                            ->prefix('$')
                                            ->step(0.01)
                                            ->minValue(0)
                                            ->hintIcon(Heroicon::InformationCircle, 'Internal cost — what the company pays. Used for reporting and P&L only; never shown on the storefront or public API.'),
                                    ]),

                                Section::make('Flags')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->components([
                                        Toggle::make('is_featured')->label('Featured'),
                                        Toggle::make('is_in_stock')->label('In stock')->default(true)
                                            ->hintIcon(Heroicon::InformationCircle, 'Uncheck to hide this package from in-stock filters and surface an out-of-stock indicator on the frontend.'),
                                        Toggle::make('requires_lab')->label('Requires lab work'),
                                    ]),
                            ]),

                        // ── Organization ──────────────────────────────
                        Tab::make('Organization')
                            ->icon(Heroicon::Tag)
                            ->columns(2)
                            ->schema([
                                Select::make('category_ids')
                                    ->label('Categories')
                                    ->multiple()
                                    ->relationship('categories', 'name')
                                    ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id'))
                                    ->preload()
                                    ->hintIcon(Heroicon::InformationCircle, 'Assigns this package to catalog categories.')
                                    ->searchable(),
                                Select::make('tag_ids')
                                    ->label('Tags')
                                    ->multiple()
                                    ->relationship('tags', 'name')
                                    ->options(fn () => Tag::query()->orderBy('name')->pluck('name', 'id'))
                                    ->preload()
                                    ->hintIcon(Heroicon::InformationCircle, 'Optional tags for filtering and related-item suggestions.')
                                    ->searchable(),
                            ]),

                        // ── Media ─────────────────────────────────────
                        Tab::make('Media')
                            ->icon(Heroicon::Photo)
                            ->schema([
                                FileUpload::make('hero_image_path')
                                    ->label('Hero image')
                                    ->image()
                                    ->imageEditor()
                                    ->disk('public')
                                    ->directory('catalog/packages')
                                    ->visibility('public')
                                    ->maxSize(5120)
                                    ->hintIcon(Heroicon::InformationCircle, 'Primary product image shown on listing cards and the package detail page.'),
                                FileUpload::make('banner_image_path')
                                    ->label('Banner image')
                                    ->image()
                                    ->imageEditor()
                                    ->disk('public')
                                    ->directory('catalog/packages/banners')
                                    ->visibility('public')
                                    ->maxSize(5120)
                                    ->hintIcon(Heroicon::InformationCircle, 'Wide banner image for the package hero section. Recommended: 1920×600px.')
                                    ->helperText('Wide banner image for the package hero section.'),
                                FileUpload::make('gallery')
                                    ->label('Gallery images')
                                    ->image()
                                    ->imageEditor()
                                    ->multiple()
                                    ->reorderable()
                                    ->disk('public')
                                    ->directory('catalog/packages/gallery')
                                    ->visibility('public')
                                    ->maxSize(5120)
                                    ->maxFiles(12)
                                    ->hintIcon(Heroicon::InformationCircle, 'Additional images for an in-page gallery. Max 12 images, 5 MB each.'),
                            ]),

                        // ── Merchandising ─────────────────────────────
                        Tab::make('Merchandising')
                            ->icon(Heroicon::Sparkles)
                            ->columns(2)
                            ->schema([
                                TextInput::make('badge_text')
                                    ->label('Badge')
                                    ->maxLength(32)
                                    ->placeholder('e.g. Best Seller, New')
                                    ->hintIcon(Heroicon::InformationCircle, 'Small promotional badge shown on listing cards, e.g. "Best Seller" or "New".')
                                    ->helperText('Optional. Shown on listing cards.'),
                                TextInput::make('position')
                                    ->numeric()
                                    ->default(0)
                                    ->hintIcon(Heroicon::InformationCircle, 'Controls display order. Lower numbers appear first.'),
                                Repeater::make('highlights')
                                    ->label('Highlights')
                                    ->hintIcon(Heroicon::InformationCircle, 'Bullet-point feature list displayed on the package detail page.')
                                    ->helperText('One bullet per line. Displayed as a feature list.')
                                    ->schema([
                                        TextInput::make('item')
                                            ->label('Highlight')
                                            ->required()
                                            ->hintIcon(Heroicon::InformationCircle, 'One short credibility line, shown under the Add to Cart button.'),
                                        TextInput::make('icon')
                                            ->label('Icon')
                                            ->maxLength(64)
                                            ->placeholder('ti ti-shield-check')
                                            ->hintIcon(Heroicon::InformationCircle, 'A Tabler icon class, shown to the left of this line. Browse them at tabler.io/icons — the class is "ti ti-" plus the icon name. An emoji works too. Leave blank for a plain check mark.'),
                                    ])
                                    ->columnSpanFull()
                                    ->reorderable()
                                    ->addActionLabel('Add highlight'),
                            ]),

                        // ── Detail page ───────────────────────────────
                        Tab::make('Detail page')
                            ->icon(Heroicon::AdjustmentsHorizontal)
                            ->schema([
                                Section::make('Content blocks')
                                    ->description('Structured content blocks for the stack detail page. The frontend decides how each placement renders (sidebar accordions vs. description tabs).')
                                    ->components([
                                        Repeater::make('detail_sections')
                                            ->hiddenLabel()
                                            ->schema([
                                                TextInput::make('title')
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->hintIcon(Heroicon::InformationCircle, 'Heading shown on the accordion or tab, e.g. "How To Use".'),
                                                Select::make('placement')
                                                    ->options([
                                                        'accordion' => 'Sidebar accordion',
                                                        'tab' => 'Description tab',
                                                    ])
                                                    ->default('accordion')
                                                    ->required()
                                                    ->native(false)
                                                    ->hintIcon(Heroicon::InformationCircle, 'Where the frontend places this block on the detail page.'),
                                                Textarea::make('content')
                                                    ->required()
                                                    ->rows(5)
                                                    ->columnSpanFull()
                                                    ->hintIcon(Heroicon::InformationCircle, 'Block copy. Plain text / simple HTML.'),
                                            ])
                                            ->columns(2)
                                            ->reorderable()
                                            ->collapsible()
                                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                            ->defaultItems(0)
                                            ->addActionLabel('Add content block'),
                                    ]),

                                Section::make('Layout')
                                    ->description('Per-package presentation knobs for the storefront detail page. Every field is optional — blank means the deployment default. Injectable sections (video, content bands, …) live on the "Page Sections" tab after the record is saved.')
                                    ->columns(2)
                                    ->components([
                                        Select::make('detail_layout.template')
                                            ->label('Page template')
                                            ->options([
                                                'classic' => 'Classic',
                                                'conversion' => 'Conversion (mobile-first)',
                                            ])
                                            ->native(false)
                                            ->placeholder('Deployment default'),
                                        Select::make('detail_layout.accordions.placement')
                                            ->label('Accordion placement')
                                            ->options([
                                                'side' => 'Info column (side)',
                                                'below' => 'Full-width below product info',
                                            ])
                                            ->native(false)
                                            ->placeholder('Deployment default'),
                                        Select::make('detail_layout.highlights_position')
                                            ->label('Highlights position')
                                            ->options([
                                                'above' => 'Above the Add to Cart button',
                                                'below' => 'Below the Add to Cart button',
                                                'none' => 'Do not show them',
                                            ])
                                            ->native(false)
                                            ->placeholder('Deployment default (above)')
                                            ->hintIcon(Heroicon::InformationCircle, 'Where the Merchandising tab’s highlights render in the buy box — the short credibility lines with icons. "None" hides them without deleting them, which is different from leaving this blank.'),
                                        Select::make('detail_layout.pair_with.desktop')
                                            ->label('Pair With per view (desktop)')
                                            ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4'])
                                            ->native(false)
                                            ->placeholder('Automatic'),
                                        Select::make('detail_layout.pair_with.mobile')
                                            ->label('Pair With per view (mobile)')
                                            ->options([1 => '1', 2 => '2'])
                                            ->native(false)
                                            ->placeholder('Automatic'),
                                        CheckboxList::make('detail_layout.rails')
                                            ->label('Bottom rails')
                                            ->options([
                                                'related' => 'People Also Bought (related items)',
                                                'stacks' => 'Stacks rail',
                                                'associated' => 'Associated products',
                                                'none' => 'None — hide every rail on this page',
                                            ])
                                            ->columns(3)
                                            ->columnSpanFull()
                                            ->hintIcon(Heroicon::InformationCircle, 'Which recommendation rails render at the bottom of this page. Leave every box unticked for the deployment default; tick "None" to show no rails at all. Those are different answers — unticked means you have not chosen, and the default can change.'),
                                    ]),
                            ]),

                        // ── Integrations ──────────────────────────────
                        Tab::make('Integrations')
                            ->icon(Heroicon::PuzzlePiece)
                            ->columns(2)
                            ->schema([
                                TextInput::make('provider_package_id')
                                    ->label('Provider package ID')
                                    ->maxLength(36)
                                    ->hintIcon(Heroicon::InformationCircle, 'UUID of the matching package record in the provider. Found in the provider admin.'),
                                TextInput::make('provider_package_sku')
                                    ->label('Provider SKU')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, "Provider's human-readable SKU for this package. Used in order submissions."),
                            ]),

                        // ── SEO ───────────────────────────────────────
                        Tab::make('SEO')
                            ->icon(Heroicon::MagnifyingGlass)
                            ->columns(2)
                            ->schema([
                                TextInput::make('meta_title')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, 'SEO title for this package page. Defaults to package name if blank.')
                                    ->helperText('Leave blank to fall back to the global SEO settings.'),
                                FileUpload::make('og_image_path')
                                    ->label('OG image')
                                    ->image()
                                    ->disk('public')
                                    ->directory('catalog/packages/og')
                                    ->visibility('public')
                                    ->maxSize(5120)
                                    ->hintIcon(Heroicon::InformationCircle, 'Open Graph image for social shares. 1200×630px recommended.'),
                                Textarea::make('meta_description')
                                    ->maxLength(500)
                                    ->rows(3)
                                    ->hintIcon(Heroicon::InformationCircle, 'SEO meta description. 150–160 chars recommended.')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }
}
