@php
    $hasLocation = filled($context['location']);
    $hasPhysicalAddress = filled($context['physical_address']);
    $hasPreferenceUrl = filled($context['preference_url']);
@endphp
Kamp Love

{!! __('You\'re registered for :event', ['event' => $context['event_title']]) !!}

{!! __('Event Details') !!}
{!! $context['event_title'] !!}
{!! $context['local_start'] !!} {!! $context['timezone'] !!}
@if($hasLocation)
{!! $context['location'] !!}
@endif

{!! __('View event details') !!}:
{!! $context['event_url'] !!}

{!! __('Questions? Contact us at :supportEmail.', ['supportEmail' => $context['support_email']]) !!}
@if($hasPhysicalAddress || $hasPreferenceUrl)

@if($hasPhysicalAddress)
{!! $context['physical_address'] !!}
@endif
@if($hasPreferenceUrl)
{!! __('Email preferences') !!}: {!! $context['preference_url'] !!}
@endif
@endif
