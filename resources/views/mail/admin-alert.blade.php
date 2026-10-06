<x-mail::message>
# {{ $alertTitle }}

{{ $alertBody }}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
Open in admin
</x-mail::button>
@endif

This is an automated operational alert.
</x-mail::message>
