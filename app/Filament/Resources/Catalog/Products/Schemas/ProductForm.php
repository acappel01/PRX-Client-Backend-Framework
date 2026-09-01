<?php

namespace App\Filament\Resources\Catalog\Products\Schemas;

use App\Enums\Catalog\IntakeSelectionMode;
use App\Enums\CatalogStatus;
use App\Enums\InventoryStatus;
use App\Models\Catalog\Category;
use App\Models\Catalog\Tag;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
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

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Product')
                    ->vertical()
                    ->persistTabInQueryString('product-tab')
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
                                    ->hintIcon(Heroicon::InformationCircle, 'URL-friendly identifier. Auto-generated; change with caution — breaks existing links.')
                                    ->helperText('Lowercase letters, numbers, hyphens.'),
                                TextInput::make('subtitle')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, 'Short sub-headline shown below the product name on listing cards.')
                                    ->columnSpanFull(),
                                Textarea::make('short_description')
                                    ->maxLength(2000)
                                    ->rows(3)
                                    ->hintIcon(Heroicon::InformationCircle, 'Brief description used in listing cards and API summary responses.')
                                    ->columnSpanFull(),
                                Textarea::make('description')
                                    ->rows(8)
                                    ->columnSpanFull()
                                    ->hintIcon(Heroicon::InformationCircle, 'Full product description shown on the detail page.')
                                    ->helperText('Long-form description for the product detail page.'),

                                Section::make('Classification')
                                    ->description('Clinical taxonomy and physical form. Manage the option lists under Shop → Product Classes / Types / Forms / Administration Methods / Units.')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->components([
                                        Select::make('product_class_id')
                                            ->label('Product class')
                                            ->relationship('productClass', 'name', fn ($query) => $query->active()->orderBy('position'))
                                            ->preload()
                                            ->searchable()
                                            ->hintIcon(Heroicon::InformationCircle, 'Top-level clinical grouping, e.g. Peptides, HRT, GLP-1.'),
                                        Select::make('product_type_id')
                                            ->label('Product type')
                                            ->relationship('productType', 'name', fn ($query) => $query->active()->orderBy('position'))
                                            ->preload()
                                            ->searchable()
                                            ->hintIcon(Heroicon::InformationCircle, 'Specific type within the class, e.g. a particular therapy line.'),
                                        Select::make('product_form_id')
                                            ->label('Product form')
                                            ->relationship('productForm', 'name', fn ($query) => $query->active()->orderBy('position'))
                                            ->preload()
                                            ->searchable()
                                            ->hintIcon(Heroicon::InformationCircle, 'Physical form: vial, troche, capsule, cream, nasal inhaler, etc.'),
                                        Select::make('administration_method_id')
                                            ->label('Administration method')
                                            ->relationship('administrationMethod', 'name', fn ($query) => $query->active()->orderBy('position'))
                                            ->preload()
                                            ->searchable()
                                            ->hintIcon(Heroicon::InformationCircle, 'Route of delivery: oral, sub-q injection, topical, etc.'),
                                        TextInput::make('volume')
                                            ->numeric()
                                            ->minValue(0)
                                            ->step(0.0001)
                                            ->hintIcon(Heroicon::InformationCircle, 'Container volume or total content amount, e.g. 10 for a 10 mg vial or 3 for a 3 ml vial.'),
                                        Select::make('volume_unit_id')
                                            ->label('Volume unit')
                                            ->relationship('volumeUnit', 'abbreviation', fn ($query) => $query->active()->orderBy('position'))
                                            ->preload()
                                            ->hintIcon(Heroicon::InformationCircle, 'Unit for the volume value: mg, ml, g, etc.'),
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
                                    ->native(false)
                                    ->hintIcon(Heroicon::InformationCircle, 'Draft and Pending products are not visible on the public site.'),
                                Select::make('inventory_status')
                                    ->options(InventoryStatus::class)
                                    ->native(false)
                                    ->placeholder('Not tracked')
                                    ->hintIcon(Heroicon::InformationCircle, 'When set, the In-stock flag is derived automatically (In Stock / Back Ordered = purchasable). Leave empty to manage the flag manually.'),

                                Section::make('Pricing')
                                    ->description('Display pricing. Used as transaction price when local checkout is configured; otherwise PRX is the source of truth.')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->components([
                                        TextInput::make('retail_price')
                                            ->numeric()->prefix('$')->step(0.01)
                                            ->hintIcon(Heroicon::InformationCircle, 'Full retail price. Shown as the crossed-out price when a sale price is set.'),
                                        TextInput::make('sale_price')
                                            ->numeric()->prefix('$')->step(0.01)
                                            ->hintIcon(Heroicon::InformationCircle, 'Active sale price. If set, this is the price displayed to customers.'),
                                        // SAME WRONG EXAMPLE THE PACKAGE FORM CARRIED. This
                                        // price is what a single purchase costs, and a card
                                        // shows it whenever it is the cheapest way in — so
                                        // "/mo" here makes a card read "As low as $149.00/mo"
                                        // for something the cart books once. Nothing validates
                                        // the value, so the form's example is the only guard,
                                        // and four products carried this mistake. Recurring
                                        // wording belongs on the product's Plans, where
                                        // BillingPeriod fills the suffix in.
                                        TextInput::make('price_suffix')
                                            ->maxLength(32)
                                            ->placeholder('usually blank — e.g. /vial')
                                            ->hintIcon(Heroicon::InformationCircle, 'Usually leave this BLANK. This price is a single purchase and is what cards show when it is the cheapest way in, so a per-period suffix like "/mo" advertises a subscription for something billed once. Use it only for a genuine per-unit price such as "/vial". Monthly and prepaid wording belongs on this product\'s Plans.'),
                                        TextInput::make('cost')
                                            ->numeric()->prefix('$')->step(0.01)->minValue(0)
                                            ->hintIcon(Heroicon::InformationCircle, 'Internal unit cost — what the company pays. Used for reporting and P&L only; never shown on the storefront or public API.'),
                                    ]),

                                Section::make('Flags')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->components([
                                        Toggle::make('is_featured')->label('Featured'),
                                        Toggle::make('is_in_stock')
                                            ->label('In stock')
                                            ->default(true)
                                            ->hintIcon(Heroicon::InformationCircle, 'Uncheck to hide this product from in-stock filters and surface an out-of-stock indicator on the frontend.'),
                                        Toggle::make('requires_lab')
                                            ->label('Requires lab work')
                                            ->helperText('Surfaces a "Lab required" badge on the public detail page.'),
                                        Toggle::make('rx_required')
                                            ->label('Prescription required')
                                            ->hintIcon(Heroicon::InformationCircle, 'Product requires a prescription; mirrors the provider rx_required flag on sync.'),
                                        Toggle::make('is_controlled_substance')
                                            ->label('Controlled substance')
                                            ->hintIcon(Heroicon::InformationCircle, 'Marks the product as a controlled substance for compliance handling and reporting.'),
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
                                    ->searchable()
                                    ->hintIcon(Heroicon::InformationCircle, 'Assigns this product to catalog categories.'),
                                Select::make('tag_ids')
                                    ->label('Tags')
                                    ->multiple()
                                    ->relationship('tags', 'name')
                                    ->options(fn () => Tag::query()->orderBy('name')->pluck('name', 'id'))
                                    ->preload()
                                    ->searchable()
                                    ->hintIcon(Heroicon::InformationCircle, 'Optional tags for filtering and related-item suggestions.'),
                            ]),

                        // ── Media ─────────────────────────────────────
                        Tab::make('Media')
                            ->icon(Heroicon::Photo)
                            ->schema([
                                FileUpload::make('hero_image_path')
                                    ->label('Hero image')
                                    ->image()->imageEditor()
                                    ->disk('public')->directory('catalog/products')->visibility('public')
                                    ->maxSize(5120)
                                    ->hintIcon(Heroicon::InformationCircle, 'Primary product image shown on listing cards and the product detail page.'),
                                FileUpload::make('gallery')
                                    ->label('Gallery images')
                                    ->image()->imageEditor()->multiple()->reorderable()
                                    ->disk('public')->directory('catalog/products/gallery')->visibility('public')
                                    ->maxSize(5120)->maxFiles(12)
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
                                    ->hintIcon(Heroicon::InformationCircle, 'Small promotional badge shown on listing cards.')
                                    ->helperText('Optional. Shown on listing cards.'),
                                TextInput::make('position')
                                    ->numeric()->default(0)
                                    ->hintIcon(Heroicon::InformationCircle, 'Controls display order. Lower numbers appear first.'),
                                Select::make('healthGoals')
                                    ->label('Health goals')
                                    ->relationship('healthGoals', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->columnSpanFull()
                                    ->hintIcon(Heroicon::InformationCircle, 'What this product is good for. Each one shows as a coloured badge on listing cards, on the product page, and on the rows of any stack that includes it. Pick the badge colour on the goal itself, under Knowledge base → Health goals.')
                                    ->helperText('These are the same goals the quiz asks about, on purpose — a visitor who picks "weight management" there should see that badge here.'),
                                Repeater::make('highlights')
                                    ->label('Highlights')
                                    ->hintIcon(Heroicon::InformationCircle, 'The short credibility list under the Add to Cart button — physician-supervised, licensed pharmacy, and so on. These are NOT the benefits diagram; that is a section you add under Page Sections.')
                                    ->helperText('Keep each line short — they sit in the narrow buy column.')
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
                                    ->description('Structured content blocks for the product detail page. The frontend decides how each placement renders (sidebar accordions vs. description tabs).')
                                    ->components([
                                        Repeater::make('detail_sections')
                                            ->hiddenLabel()
                                            ->schema([
                                                TextInput::make('title')
                                                    ->required()->maxLength(255)
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
                                                    ->required()->rows(5)->columnSpanFull()
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
                                    ->description('Per-product presentation knobs for the storefront detail page. Every field is optional — blank means the deployment default. Injectable sections (video, content bands, …) live on the "Page Sections" tab after the record is saved.')
                                    ->columns(2)
                                    ->components([
                                        Select::make('detail_layout.template')
                                            ->label('Page template')
                                            ->options([
                                                'classic' => 'Classic',
                                                'conversion' => 'Conversion (mobile-first)',
                                            ])
                                            ->native(false)
                                            ->placeholder('Deployment default')
                                            ->hintIcon(Heroicon::InformationCircle, 'Which detail-page composition the frontend renders for this record.'),
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
                                TextInput::make('provider_product_id')
                                    ->label('Provider product ID')
                                    ->maxLength(36)
                                    ->hintIcon(Heroicon::InformationCircle, 'UUID of the matching product in the provider. Found in the provider admin.'),
                                TextInput::make('provider_product_sku')
                                    ->label('Provider SKU')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, "Provider's human-readable SKU. Used in order submissions."),
                                Radio::make('intake_selection_mode')
                                    ->label('What the clinical intake orders')
                                    ->options(fn (): array => collect(IntakeSelectionMode::cases())
                                        ->mapWithKeys(fn (IntakeSelectionMode $m) => [$m->value => $m->label()])
                                        ->all())
                                    ->descriptions(fn (): array => collect(IntakeSelectionMode::cases())
                                        ->mapWithKeys(fn (IntakeSelectionMode $m) => [$m->value => $m->helperText()])
                                        ->all())
                                    ->default(IntakeSelectionMode::Product->value)
                                    ->required()
                                    ->columnSpanFull()
                                    ->helperText('Choose "Product type" when this item\'s strength or presentation is decided by the prescriber rather than the patient. It needs the Classification tab\'s product type mapped to the provider — an unmapped type means this item is left OFF the order rather than sent at a dose nobody chose.'),
                            ]),

                        // ── SEO ───────────────────────────────────────
                        Tab::make('SEO')
                            ->icon(Heroicon::MagnifyingGlass)
                            ->columns(2)
                            ->schema([
                                TextInput::make('meta_title')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, 'SEO title for this product page. Defaults to product name if blank.'),
                                FileUpload::make('og_image_path')
                                    ->label('OG image')
                                    ->image()
                                    ->disk('public')->directory('catalog/products/og')->visibility('public')
                                    ->maxSize(5120)
                                    ->hintIcon(Heroicon::InformationCircle, 'Open Graph image for social shares. 1200×630px recommended.'),
                                Textarea::make('meta_description')
                                    ->maxLength(500)->rows(3)->columnSpanFull()
                                    ->hintIcon(Heroicon::InformationCircle, 'SEO meta description. 150–160 chars recommended.'),
                            ]),
                    ]),
            ]);
    }
}
