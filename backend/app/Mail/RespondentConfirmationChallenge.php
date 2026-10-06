<?php

namespace HiEvents\Mail;

use Illuminate\Mail\Mailable;

final class RespondentConfirmationChallenge extends Mailable
{
    public function __construct(public readonly string $confirmationCode, public readonly ?string $completionUrl = null) {}

    public function build(): self
    {
        if ($this->completionUrl !== null) {
            return $this->subject(__('Complete your Kamp Love waiver'))->view('emails.registration.completion-invitation')->text('emails.registration.completion-invitation-text');
        }

        return $this->subject(__('Verify your Kamp Love waiver contact choices'))
            ->view('emails.registration.respondent-challenge')
            ->text('emails.registration.respondent-challenge-text');
    }
}
