<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\PageStatus;
use App\Filament\Support\SectionImagePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Tabs::make('Page')
                    ->vertical()
                    ->persistTabInQueryString('page-tab')
                    ->columnSpanFull()
                    ->tabs([

                        // ── Details ───────────────────────────────────
                        Tab::make('Details')
                            ->icon(Heroicon::DocumentText)
                            ->columns(2)
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->hintIcon(Heroicon::InformationCircle, 'Page title shown in the browser tab and H1.')
                                    ->afterStateUpdated(function (string $operation, ?string $state, callable $set, callable $get): void {
                                        if ($operation === 'create' && blank($get('slug')) && filled($state)) {
                                            $set('slug', Str::slug($state));
                                        }
                                    }),
                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->alphaDash()
                                    ->hintIcon(Heroicon::InformationCircle, 'URL path for this page, e.g. "about-us". Safe to change: the old address keeps working and sends visitors here automatically.')
                                    ->helperText('URL path. Lowercase letters, numbers, hyphens.'),
                                Select::make('status')
                                    ->options(PageStatus::class)
                                    ->default(PageStatus::Draft->value)
                                    ->required()
                                    ->hintIcon(Heroicon::InformationCircle, 'Draft pages are not publicly accessible.')
                                    ->native(false),
                                TextInput::make('template')
                                    ->required()
                                    ->default('default')
                                    ->maxLength(64)
                                    ->hintIcon(Heroicon::InformationCircle, 'Page layout template used by the frontend renderer.')
                                    ->helperText('Reserved for future per-page Blade templates.'),
                                DateTimePicker::make('publish_at')
                                    ->hintIcon(Heroicon::InformationCircle, 'Schedule a future publish date. The page stays draft until this time arrives.')
                                    ->helperText('Optional. If set in the future, the page stays draft-equivalent until that time.'),
                            ]),

                        // ── Title banner ──────────────────────────────
                        Tab::make('Title banner')
                            ->icon(Heroicon::Photo)
                            ->columns(2)
                            ->schema([
                                Toggle::make('title_banner.enabled')
                                    ->label('Show title banner')
                                    ->helperText('Optional banner the frontend renders above the page content — background image, title, subtitle, and breadcrumbs.')
                                    ->live()
                                    ->columnSpanFull(),
                                SectionImagePicker::make('title_banner.background_image')
                                    ->label('Background image')
                                    ->visible(fn (callable $get): bool => (bool) $get('title_banner.enabled'))
                                    ->columnSpanFull(),
                                TextInput::make('title_banner.title_override')
                                    ->label('Title override')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, 'Overrides the page title inside the banner. Leave blank to show the page title.')
                                    ->visible(fn (callable $get): bool => (bool) $get('title_banner.enabled')),
                                TextInput::make('title_banner.subtitle')
                                    ->label('Subtitle')
                                    ->maxLength(255)
                                    ->visible(fn (callable $get): bool => (bool) $get('title_banner.enabled')),
                                Textarea::make('title_banner.intro_text')
                                    ->label('Intro text')
                                    ->rows(2)
                                    ->maxLength(1000)
                                    ->visible(fn (callable $get): bool => (bool) $get('title_banner.enabled'))
                                    ->columnSpanFull(),
                                Toggle::make('title_banner.show_breadcrumbs')
                                    ->label('Show breadcrumbs')
                                    ->default(true)
                                    ->helperText('The frontend computes the breadcrumb trail from the URL.')
                                    ->visible(fn (callable $get): bool => (bool) $get('title_banner.enabled')),
                            ]),

                        // ── SEO ───────────────────────────────────────
                        Tab::make('SEO')
                            ->icon(Heroicon::MagnifyingGlass)
                            ->columns(2)
                            ->schema([
                                TextInput::make('meta_title')
                                    ->maxLength(255)
                                    ->hintIcon(Heroicon::InformationCircle, 'SEO title for this page. Defaults to page title if blank.')
                                    ->helperText('Leave blank to fall back to the global SEO settings (/admin/settings/seo).'),
                                TextInput::make('og_image_path')
                                    ->label('OG image path')
                                    ->maxLength(2048)
                                    ->hintIcon(Heroicon::InformationCircle, 'Path to the Open Graph image. Upload via /admin/media, then paste the path here.')
                                    ->helperText('Upload via /admin/media, then paste the path here.'),
                                Textarea::make('meta_description')
                                    ->maxLength(500)
                                    ->rows(3)
                                    ->hintIcon(Heroicon::InformationCircle, 'SEO meta description for this page. 150–160 chars recommended.')
                                    ->columnSpanFull(),
                                Toggle::make('noindex')
                                    ->label('Hide from search engines')
                                    ->helperText('Forces this page to emit `noindex` even if global indexing is on.'),
                            ]),
                    ]),
            ]);
    }
}
