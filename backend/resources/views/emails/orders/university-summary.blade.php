@php
    use Carbon\Carbon;
    use HiEvents\Helper\Currency;
    use HiEvents\Helper\DateHelper;

    $isPending = $order->isOrderAwaitingOfflinePayment();
    $eventDate = (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('F j, Y');
    $eventTime = (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('g:i A');
    $supportEmail = $eventSettings->getSupportEmail() ?: $organizer->getEmail();
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $renderedTemplate?->subject ?? __('Your Order is Confirmed!') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;color:#222222;font-family:'DM Sans',Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;border-collapse:collapse;background-color:#f4f5f7;">
    <tr><td align="center" style="padding:12px;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;table-layout:fixed;border-collapse:collapse;background-color:#ffffff;border:1px solid #d8dbe2;border-radius:10px;">
            <tr><td height="10" style="height:10px;line-height:10px;font-size:0;background-color:{{ $universityTheme->primary }};color:{{ $universityTheme->onPrimary }};border-radius:10px 10px 0 0;">&nbsp;</td></tr>
            <tr><td style="padding:28px 20px 32px;word-break:break-word;overflow-wrap:anywhere;">
                <p style="margin:0 0 24px;color:{{ $universityTheme->primary }};font-size:14px;line-height:20px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Kamp Love</p>
                @if($renderedTemplate)
                    <div style="margin:0 0 28px;color:#222222;font-size:16px;line-height:25px;">{!! $renderedTemplate->body !!}</div>
                @elseif($isPending)
                    <h1 style="margin:0 0 18px;color:#222222;font-size:28px;line-height:34px;font-weight:700;">{{ __('Your spot at :eventTitle is being held', ['eventTitle' => $event->getTitle()]) }}</h1>
                @else
                    <h1 style="margin:0 0 18px;color:#222222;font-size:28px;line-height:34px;font-weight:700;">{{ __('Your order is confirmed') }}</h1>
                    <p style="margin:0 0 28px;color:#222222;font-size:17px;line-height:27px;">{{ __('Your order for :eventTitle on :eventDate at :eventTime was successful. Please find your order details below.', ['eventTitle' => $event->getTitle(), 'eventDate' => $eventDate, 'eventTime' => $eventTime]) }}</p>
                @endif

                @if($isPending)
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background-color:#e9edf2;border-radius:8px;"><tr><td style="padding:18px 20px;color:#222222;font-size:16px;line-height:24px;">{{ __('Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}<br><br><strong>{{ __('Payment Instructions') }}</strong><br>{{ __('Please follow the instructions below to complete your payment.') }}<br>{!! $eventSettings->getOfflinePaymentInstructions() !!}</td></tr></table>
                @endif

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;background-color:{{ $universityTheme->secondarySoft }};border-radius:8px;"><tr><td style="padding:18px 20px;color:#222222;font-size:16px;line-height:25px;">
                    <strong>{{ __('Event Details') }}</strong><br>
                    {{ $event->getTitle() }}<br>
                    {{ __(':date at :time', ['date' => $eventDate, 'time' => $eventTime]) }}
                    @if($location)<br>{{ $location }}@endif
                </td></tr></table>

                @if($eventSettings->getPostCheckoutMessage() && $order->isOrderCompleted())
                    <div style="margin:28px 0 0;color:#222222;font-size:16px;line-height:25px;">{!! $eventSettings->getPostCheckoutMessage() !!}</div>
                @endif

                <div style="margin:28px 0;color:#222222;font-size:16px;line-height:25px;"><strong>{{ __('Order Summary') }}</strong><br>{{ __('Order Number:') }} {{ $order->getPublicId() }}<br>{{ __('Total Amount:') }} {{ Currency::format($order->getTotalGross(), $event->getCurrency()) }}</div>

                @if($renderedTemplate?->cta)
                    <p style="margin:0 0 18px;"><a href="{{ $renderedTemplate->cta['url'] }}" style="color:{{ $universityTheme->secondary }};font-weight:700;text-decoration:underline;">{{ $renderedTemplate->cta['label'] }}</a></p>
                @endif
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;"><tr><td align="center" bgcolor="{{ $universityTheme->secondary }}" style="border-radius:999px;background-color:{{ $universityTheme->secondary }};"><a href="{{ $orderUrl }}" style="display:block;width:100%;box-sizing:border-box;padding:15px 12px;color:{{ $universityTheme->onSecondary }};font-size:16px;line-height:20px;font-weight:700;text-align:center;text-decoration:none;border-radius:999px;">{{ __('View Order Summary & Tickets') }}</a></td></tr></table>
                <p style="margin:20px 0 0;color:#555b66;font-size:14px;line-height:21px;word-break:break-all;overflow-wrap:anywhere;"><a href="{{ $orderUrl }}" style="display:block;max-width:100%;color:{{ $universityTheme->secondary }};text-decoration:underline;word-break:break-all;overflow-wrap:anywhere;">{{ $orderUrl }}</a></p>
                @if(!empty($supportEmail))<p style="margin:20px 0 0;color:#555b66;font-size:14px;line-height:21px;">{{ __('Questions? Contact us at :supportEmail.', ['supportEmail' => $supportEmail]) }}</p>@endif
                {!! $eventSettings->getGetEmailFooterHtml() !!}
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
