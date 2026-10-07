<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>{{ __('Complete your Kamp Love waiver') }}</title>
<link rel="stylesheet" href="/api/registration/invitation-style">
<script defer src="/api/registration/invitation-script"></script></head><body style="margin:0"><main class="publicSurface surface"><div class="card">
<p>Kamp Love · Grand Valley State University</p><h1 class="title">{{ __('Complete your Kamp Love waivers') }}</h1>
<p>{{ __('Choose who will sign for each attendee, then continue to the waiver. Buying a ticket does not sign a waiver.') }}</p>
<p class="status" id="status" role="status" aria-live="polite">{{ __('Opening your waivers…') }}</p>
<form class="form" id="choices" hidden><div id="siblings"></div><label class="acceptance"><input id="acknowledged" type="checkbox" required> {{ __('I confirm the appropriate signer for every attendee.') }}</label><p><small>{{ __('An adult signs for themself. A parent or legal guardian signs for a minor. This invitation gives access to this order; it does not prove identity or guardianship. Do not forward it.') }}</small></p><button class="btn btnPrimary" type="submit">{{ __('Continue to waivers') }}</button></form>
<section id="ready" hidden><h2>{{ __('Your waivers') }}</h2><p>{{ __('Each named adult must complete their own waiver, or a parent or legal guardian must complete it for a minor. Only the signer should use the corresponding link.') }}</p><nav id="links"></nav><button class="btn btnPrimary" id="resume" type="button">{{ __('Refresh waiver status') }}</button></section>
<noscript>{{ __('Please enable JavaScript to open this private invitation.') }}</noscript></div></main></body></html>
