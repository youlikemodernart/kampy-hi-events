{{ __('Your event is coming up') }}

{{ __('You are registered for :event.', ['event' => $context['event_title']]) }}

{{ $context['event_title'] }}
{{ $context['local_start'] }} {{ $context['timezone'] }}
@if(!empty($context['location'])){{ $context['location'] }}
@endif
{{ __('View event details') }}: {{ $context['event_url'] }}

{{ $context['physical_address'] }}
{{ __('Email preferences') }}: {{ $context['preference_url'] }}
