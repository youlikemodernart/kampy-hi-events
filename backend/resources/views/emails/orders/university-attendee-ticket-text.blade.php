@php
    use Carbon\Carbon;
    use HiEvents\Helper\DateHelper;
    $isPending = $order->isOrderAwaitingOfflinePayment();
    $eventDate = (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('F j, Y');
    $eventTime = (new Carbon(DateHelper::convertFromUTC($event->getStartDate(), $event->getTimezone())))->format('g:i A');
    $supportEmail = $eventSettings->getSupportEmail() ?: $organizer->getEmail();
    $venueName = $eventSettings->getConfirmationVenueName();
@endphp
Kamp Love

@if($renderedTemplate)
{!! $plainRenderedBody !!}
@elseif($eventSettings->getIsOnlineEvent())
{!! __('You\'re all set for :eventTitle', ['eventTitle' => $event->getTitle()]) !!}
@elseif($venueName)
{!! __('You\'re going to :eventTitle at :venueName', ['eventTitle' => $event->getTitle(), 'venueName' => $venueName]) !!}
@else
{!! __('You\'re going to :eventTitle', ['eventTitle' => $event->getTitle()]) !!}
@endif

@if($isPending)
{!! __('Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') !!}
@endif

{!! __('Event Details') !!}
{!! $event->getTitle() !!}
{!! __(':date at :time', ['date' => $eventDate, 'time' => $eventTime]) !!}
@if($location)
{!! $location !!}
@endif

{!! __('Please find your ticket details below.') !!}

{!! __('View Ticket') !!}:
{!! $ticketUrl !!}

@if($renderedTemplate?->cta)
{!! $renderedTemplate->cta['label'] !!}: {!! $renderedTemplate->cta['url'] !!}
@endif
@if(!empty($supportEmail))
{!! __('Questions? Reply to this email or contact us at :supportEmail.', ['supportEmail' => $supportEmail]) !!}
@endif
@if($plainEmailFooter)

{!! $plainEmailFooter !!}
@endif
