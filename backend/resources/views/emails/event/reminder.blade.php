<!doctype html>
<html lang="en">
<body style="margin:0;padding:0;background-color:#ffffff;color:#222222;font-family:DM Sans,Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:24px;">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;">
<tr><td bgcolor="{{ $theme->primary }}" style="padding:24px;background-color:{{ $theme->primary }};color:{{ $theme->onPrimary }};"><strong>Kamp Love</strong></td></tr>
<tr><td style="padding:32px;color:#222222;"><h1 style="margin:0 0 16px;color:#222222;">{{ __('Your event is coming up') }}</h1>
<p>{{ __('You are registered for :event.', ['event' => $context['event_title']]) }}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td bgcolor="{{ $theme->secondarySoft }}" style="padding:16px;background-color:{{ $theme->secondarySoft }};color:#222222;"><strong>{{ $context['event_title'] }}</strong><br>{{ $context['local_start'] }} {{ $context['timezone'] }}@if(!empty($context['location']))<br>{{ $context['location'] }}@endif</td></tr></table>
<p><a href="{{ $context['event_url'] }}" style="display:inline-block;padding:12px 18px;background-color:{{ $theme->secondary }};color:{{ $theme->onSecondary }};text-decoration:none;">{{ __('View event details') }}</a></p>
<p>{{ __('Event details:') }} <a href="{{ $context['event_url'] }}" style="color:{{ $theme->secondary }};">{{ $context['event_url'] }}</a></p>
<p style="font-size:12px;color:#222222;">{{ $context['physical_address'] }}<br><a href="{{ $context['preference_url'] }}" style="color:{{ $theme->secondary }};">{{ __('Email preferences') }}</a></p>
</td></tr></table></td></tr></table>
</body></html>
