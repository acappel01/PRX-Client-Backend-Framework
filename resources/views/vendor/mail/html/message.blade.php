{{-- Laravel's layout with the brand in place of config('app.name') / config('app.url'),
     which on this backend name the admin. See App\Services\Mail\MailBrand. --}}
@php($mailBrand = app(\App\Services\Mail\MailBrand::class))
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
@if ($mailBrand->url())
<x-mail::header :url="$mailBrand->url()">
{{ $mailBrand->name() }}
</x-mail::header>
@else
<tr>
<td class="header">
{{ $mailBrand->name() }}
</td>
</tr>
@endif
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $mailBrand->name() }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
