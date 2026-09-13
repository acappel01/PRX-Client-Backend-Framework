{{-- Brand in place of config('app.name') / config('app.url'); see the html twin. --}}
@php($mailBrand = app(\App\Services\Mail\MailBrand::class))
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        @if ($mailBrand->url())
            <x-mail::header :url="$mailBrand->url()">
                {{ $mailBrand->name() }}
            </x-mail::header>
        @else
            {{ $mailBrand->name() }}
        @endif
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ $mailBrand->name() }}. @lang('All rights reserved.')
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
