@php
    $hasLocation = filled($context['location']);
    $hasPhysicalAddress = filled($context['physical_address']);
    $hasPreferenceUrl = filled($context['preference_url']);
    $supportLink = '<a href="mailto:'.e($context['support_email']).'" style="color:'.e($theme->secondary).';text-decoration:underline;">'.e($context['support_email']).'</a>';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $context['event_title'] }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;color:#222222;font-family:'DM Sans',Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;mso-hide:all;">{{ $context['preheader'] }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;border-collapse:collapse;background-color:#f4f5f7;">
    <tr><td align="center" style="padding:12px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;table-layout:fixed;border-collapse:collapse;background-color:#ffffff;border:1px solid #d8dbe2;border-radius:10px;">
            <tr><td height="10" style="height:10px;line-height:10px;font-size:0;background-color:{{ $theme->primary }};color:{{ $theme->onPrimary }};border-radius:10px 10px 0 0;">&nbsp;</td></tr>
            <tr><td style="padding:28px 20px 32px;word-break:break-word;overflow-wrap:anywhere;">
                <p style="margin:0 0 24px;color:{{ $theme->primary }};font-size:14px;line-height:20px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Kamp Love</p>
                <h1 style="margin:0 0 18px;color:#222222;font-size:28px;line-height:34px;font-weight:700;">{{ __('You\'re registered for :event', ['event' => $context['event_title']]) }}</h1>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background-color:{{ $theme->secondarySoft }};border-radius:8px;"><tr><td style="padding:18px 20px;color:#222222;font-size:16px;line-height:25px;">
                    <strong>{{ __('Event Details') }}</strong><br>
                    {{ $context['event_title'] }}<br>
                    {{ $context['local_start'] }} {{ $context['timezone'] }}
                    @if($hasLocation)<br>{{ $context['location'] }}@endif
                </td></tr></table>

                <div style="height:28px;line-height:28px;font-size:0;">&nbsp;</div>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;"><tr><td align="center" bgcolor="{{ $theme->secondary }}" style="border-radius:999px;background-color:{{ $theme->secondary }};"><a href="{{ $context['event_url'] }}" style="display:block;width:100%;box-sizing:border-box;padding:15px 12px;color:{{ $theme->onSecondary }};font-size:16px;line-height:20px;font-weight:700;text-align:center;text-decoration:none;border-radius:999px;">{{ __('View event details') }}</a></td></tr></table>
                <p style="margin:20px 0 0;color:#555b66;font-size:14px;line-height:21px;word-break:break-all;overflow-wrap:anywhere;"><a href="{{ $context['event_url'] }}" style="display:block;max-width:100%;color:{{ $theme->secondary }};text-decoration:underline;word-break:break-all;overflow-wrap:anywhere;">{{ $context['event_url'] }}</a></p>
                <p style="margin:20px 0 0;color:#555b66;font-size:14px;line-height:21px;">{!! __('Questions? Contact us at :supportEmail.', ['supportEmail' => $supportLink]) !!}</p>
                @if($hasPhysicalAddress || $hasPreferenceUrl)
                    <div style="height:28px;line-height:28px;font-size:0;">&nbsp;</div>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td style="padding:20px 0 0;border-top:1px solid #d8dbe2;">
                        @if($hasPhysicalAddress)<p style="margin:0;color:#555b66;font-size:14px;line-height:21px;">{{ $context['physical_address'] }}</p>@endif
                        @if($hasPreferenceUrl)<p style="margin:0;color:#555b66;font-size:14px;line-height:21px;"><a href="{{ $context['preference_url'] }}" style="color:{{ $theme->secondary }};text-decoration:underline;">{{ __('Email preferences') }}</a></p>@endif
                    </td></tr></table>
                @endif
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
