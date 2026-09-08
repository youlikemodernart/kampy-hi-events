@php use HiEvents\Helper\DateHelper; @endphp
@php /** @uses \HiEvents\Mail\Order\OrderSummary */ @endphp
@php /** @var \HiEvents\DomainObjects\EventDomainObject $event */ @endphp
@php /** @var \HiEvents\DomainObjects\EventSettingDomainObject $eventSettings */ @endphp
@php /** @var \HiEvents\DomainObjects\OrganizerDomainObject $organizer */ @endphp
@php /** @var \HiEvents\DomainObjects\AttendeeDomainObject $attendee */ @endphp
@php /** @var \HiEvents\DomainObjects\OrderDomainObject $order */ @endphp

@php /** @var string $ticketUrl */ @endphp
@php /** @see \HiEvents\Mail\Attendee\AttendeeTicketMail */ @endphp

<x-mail::message>
@php
    /**
     * Rule C-1/C-2. One complete translatable message per state with named
     * placeholders. The previous form translated only the fragment "You're going
     * to" and concatenated the event title outside it, which no translator can
     * reorder. Venue name only: a formatted street address reads like a label.
     */
    $venueName = $eventSettings->getConfirmationVenueName();
    $isOnline = $eventSettings->getIsOnlineEvent();
@endphp

@if($isOnline)
# {{ __('You\'re all set for :eventTitle', ['eventTitle' => $event->getTitle()]) }} 🎉
@elseif($venueName)
# {{ __('You\'re going to :eventTitle at :venueName', ['eventTitle' => $event->getTitle(), 'venueName' => $venueName]) }} 🎉
@else
# {{ __('You\'re going to :eventTitle', ['eventTitle' => $event->getTitle()]) }} 🎉
@endif
<br>
<br>
@if($order->isOrderAwaitingOfflinePayment())
<div style="border-radius: 10px; background-color: #e9edf2; color: #171717; border-left: 4px solid #40607d; margin-bottom: 1.5rem; padding: 1rem;">
<p>
{{ __('ℹ️ Your order is pending payment. Tickets have been issued but will not be valid until payment is received.') }}
</p>
</div>
@endif

{{ __('Please find your ticket details below.') }}

<x-mail::button :url="$ticketUrl">
{{ __('View Ticket') }}
</x-mail::button>

@php $supportEmail = $eventSettings->getSupportEmail() ?: $organizer->getEmail(); @endphp
@if(!empty($supportEmail))
{{ __('Questions? Reply to this email or contact us at :supportEmail.', ['supportEmail' => $supportEmail]) }}
@endif

{{ __('Best regards,') }}<br>
{{ $organizer->getName() ?: config('app.name') }}

{!! $eventSettings->getGetEmailFooterHtml() !!}
</x-mail::message>
