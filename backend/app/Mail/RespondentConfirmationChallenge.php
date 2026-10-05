<?php

namespace HiEvents\Mail;

use Illuminate\Mail\Mailable;

final class RespondentConfirmationChallenge extends Mailable
{
    public function __construct(public readonly string $confirmationCode) {}

    public function build(): self
    {
        return $this->subject(__('Verify your Kamp Love waiver contact choices'))
            ->view('emails.registration.respondent-challenge')
            ->text('emails.registration.respondent-challenge-text');
    }
}
