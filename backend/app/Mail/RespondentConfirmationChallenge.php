<?php

namespace HiEvents\Mail;

use HiEvents\Services\Infrastructure\Mail\CompletionInvitationPostmarkClient;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

final class RespondentConfirmationChallenge extends Mailable
{
    public function __construct(public readonly string $confirmationCode, public readonly ?string $completionUrl = null) {}

    public function build(): self
    {
        if ($this->completionUrl !== null) {
            $this->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader(CompletionInvitationPostmarkClient::HEADER, '1');
                $message->getHeaders()->addTextHeader('X-PM-TrackOpens', 'false');
                $message->getHeaders()->addTextHeader('X-PM-TrackLinks', 'None');
            });

            return $this->subject(__('Complete your Kamp Love waiver'))->view('emails.registration.completion-invitation')->text('emails.registration.completion-invitation-text');
        }

        return $this->subject(__('Verify your Kamp Love waiver contact choices'))
            ->view('emails.registration.respondent-challenge')
            ->text('emails.registration.respondent-challenge-text');
    }
}
