<x-mail::message>
# {{ $firstName ? $firstName.', your plan is ready' : 'Your plan is ready' }}

Thanks for taking the time. Your plan is waiting whenever you are.

<x-mail::button :url="$planUrl">
Open my plan
</x-mail::button>

{{-- The link is keyed by an opaque UUID, so it is worth saying plainly that
     it is personal rather than leaving someone to forward it. --}}
This link is personal to you — please don't share it.

Thanks,<br>
{{ app(\App\Services\Mail\MailBrand::class)->name() }}
</x-mail::message>
