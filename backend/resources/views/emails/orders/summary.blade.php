@php use Carbon\Carbon; use HiEvents\Helper\Currency; use HiEvents\Helper\DateHelper; @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var string $orderUrl */ @endphp

@php /** @see \HiEvents\Mail\Order\OrderSummary */ @endphp

<x-mail::message>
@php
    $venueName = $eventSettings->getConfirmationVenueName();
    $isOnline = $eventSettings->getIsOnlineEvent();
@endphp

@if($order->isOrderAwaitingOfflinePayment())
# {{ __('Your spot at :eventTitle is being held', ['eventTitle' => $event->getTitle()]) }}
@elseif($isOnline)
# {{ __('You\'re all set for :eventTitle', ['eventTitle' => $event->getTitle()]) }} 🎉
@elseif($venueName)
# {{ __('You\'re going to :eventTitle at :venueName', ['eventTitle' => $event->getTitle(), 'venueName' => $venueName]) }} 🎉
@else
# {{ __('You\'re going to :eventTitle', ['eventTitle' => $event->getTitle()]) }} 🎉
@endif

@if($order->isOrderAwaitingOfflinePayment() === false)

<p>
{{ __('Congratulations! Your order for :eventTitle on :eventDate at :eventTime was successful. Please find your order details below.', ['eventTitle' => $event->getTitle(), 'eventDate' => (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('F j, Y'), 'eventTime' => (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('g:i A')]) }}
</p>

@else

<div>
<p>
{{ __('Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>

<div style="border-radius: 10px; background-color: #e9edf2; color: #171717; border-left: 4px solid #40607d; margin-bottom: 1.5rem; padding: 1rem;">
<h2>{{ __('Payment Instructions') }}</h2>
{{ __('Please follow the instructions below to complete your payment.') }}
{!! $eventSettings->getOfflinePaymentInstructions() !!}
</div>
</div>

@endif

<p>

# {{ __('Event Details') }}
**{{ __('Event Name:') }}** {{ $event->getTitle() }}
    <br>
**{{ __('Date & Time:') }}** {{ __(':date at :time', ['date' => (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('F j, Y'), 'time' => (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('g:i A')]) }}

</p>

@if($eventSettings->getPostCheckoutMessage() && $order->isOrderCompleted())
<p>

# {{ __('Additional Information') }}

{!! $eventSettings->getPostCheckoutMessage() !!}

</p>
@endif

# {{ __('Order Summary') }}
- **{{ __('Order Number:') }}** {{ $order->getPublicId() }}
- **{{ __('Total Amount:') }}** {{ Currency::format($order->getTotalGross(), $event->getCurrency()) }}

<x-mail::button :url="$orderUrl">
    {{ __('View Order Summary & Tickets') }}
</x-mail::button>

@php $supportEmail = $eventSettings->getSupportEmail() ?: $organizer->getEmail(); @endphp
@if(!empty($supportEmail))
{{ __('Questions? Contact us at :supportEmail.', ['supportEmail' => $supportEmail]) }}
@endif

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

{!! $eventSettings->getGetEmailFooterHtml() !!}
</x-mail::message>
