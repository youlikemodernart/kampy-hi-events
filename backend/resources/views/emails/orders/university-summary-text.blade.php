@php
    use Carbon\Carbon;
    use HiEvents\Helper\Currency;
    use HiEvents\Helper\DateHelper;
    $isPending = $order->isOrderAwaitingOfflinePayment();
    $eventDate = (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('F j, Y');
    $eventTime = (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('g:i A');
    $supportEmail = $eventSettings->getSupportEmail() ?: $organizer->getEmail();
@endphp
Kamp Love

@if($renderedTemplate)
{!! $plainRenderedBody !!}
@elseif($isPending)
{!! __('Your spot at :eventTitle is being held', ['eventTitle' => $event->getTitle()]) !!}
@else
{!! __('Your order is confirmed') !!}

{!! __('Your order for :eventTitle on :eventDate at :eventTime was successful. Please find your order details below.', ['eventTitle' => $event->getTitle(), 'eventDate' => $eventDate, 'eventTime' => $eventTime]) !!}
@endif

@if($isPending)
{!! __('Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') !!}

{!! __('Payment Instructions') !!}
{!! __('Please follow the instructions below to complete your payment.') !!}
{!! $plainOfflinePaymentInstructions !!}
@endif

{!! __('Event Details') !!}
{!! $event->getTitle() !!}
{!! __(':date at :time', ['date' => $eventDate, 'time' => $eventTime]) !!}
@if($location)
{!! $location !!}
@endif

@if($eventSettings->getPostCheckoutMessage() && $order->isOrderCompleted())
{!! $plainPostCheckoutMessage !!}

@endif
{!! __('Order Summary') !!}
{!! __('Order Number:') !!} {!! $order->getPublicId() !!}
{!! __('Total Amount:') !!} {!! Currency::format($order->getTotalGross(), $event->getCurrency()) !!}

{!! __('View Order Summary & Tickets') !!}:
{!! $orderUrl !!}

@if($renderedTemplate?->cta)
{!! $renderedTemplate->cta['label'] !!}: {!! $renderedTemplate->cta['url'] !!}
@endif
@if(!empty($supportEmail))
{!! __('Questions? Contact us at :supportEmail.', ['supportEmail' => $supportEmail]) !!}
@endif
@if($plainEmailFooter)

{!! $plainEmailFooter !!}
@endif
