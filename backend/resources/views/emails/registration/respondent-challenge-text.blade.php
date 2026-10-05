Kamp Love · Grand Valley State University

{{ __('Verify your waiver contact choices') }}

{{ __('Return to the order page where you requested verification and paste this code into the verification field:') }}

{{ $confirmationCode }}

{{ __('This code expires in :minutes minutes. It verifies access to the purchase email address, not guardian status or waiver consent.', ['minutes' => config('respondent-confirmation.ttl_minutes')]) }}

{{ __('Do not forward this code. If you did not request it, ignore this email. Opening this message does not change any contact choices.') }}
