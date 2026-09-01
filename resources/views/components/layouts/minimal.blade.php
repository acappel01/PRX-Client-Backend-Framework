{{--
    Minimal server-rendered layout — no nav, no footer, no hardcoded brand.
    Used only for utility pages (e.g. prescribe-rx embed handoff) that must be
    served by Laravel rather than the decoupled frontend.

    IT IS NOT UNSTYLED, AND IT IS STILL BRAND-AGNOSTIC. The page reads this
    install's ThemeSettings — the same colours and fonts the frontend applies —
    and exposes them as CSS custom properties, so the handoff reads as part of
    whichever site sent the visitor here rather than as a bare Laravel page.
    Nothing client-specific is hardcoded; every value comes from the database.

    Both the colours and the font names reach a <style> tag, so both are
    sanitised on the way in.
--}}
@props(['title' => 'Loading…'])

@php
    $theme = app(\App\Settings\ThemeSettings::class);

    /** Only a literal hex reaches the stylesheet; anything else falls back. */
    $color = static function (?string $value, string $fallback): string {
        $value = trim((string) $value);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1
            ? $value
            : $fallback;
    };

    /** Font names are quoted into a stack, so strip anything that could escape it. */
    $font = static function (?string $value, string $fallback): string {
        $value = trim(preg_replace('/[^A-Za-z0-9 \-]/', '', (string) $value) ?? '');

        return $value !== '' ? $value : $fallback;
    };

    $primary = $color($theme->primary_color ?? null, '#101010');
    $accent = $color($theme->accent_color ?? null, '#101010');
    $background = $color($theme->background_color ?? null, '#f7f5f1');
    $text = $color($theme->text_color ?? null, '#101010');
    $fontDisplay = $font($theme->font_display ?? null, 'Georgia');
    $fontBody = $font($theme->font_body ?? null, 'Helvetica Neue');

    $googleFamilies = collect([$fontDisplay, $fontBody])
        ->unique()
        ->map(fn (string $f) => 'family='.str_replace(' ', '+', $f).':wght@400;500;600')
        ->implode('&');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?{{ $googleFamilies }}&display=swap">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        :root {
            --brand-primary: {{ $primary }};
            --brand-accent: {{ $accent }};
            --brand-bg: {{ $background }};
            --brand-text: {{ $text }};
            --brand-font-display: "{{ $fontDisplay }}", Georgia, serif;
            --brand-font-body: "{{ $fontBody }}", system-ui, -apple-system, sans-serif;
        }

        html, body {
            background: var(--brand-bg);
            color: var(--brand-text);
            font-family: var(--brand-font-body);
        }

        .brand-display {
            font-family: var(--brand-font-display);
        }
    </style>

    @stack('head')
</head>
<body class="min-h-screen antialiased">
    {{ $slot }}
    @livewireScripts
    @stack('scripts')
</body>
</html>
