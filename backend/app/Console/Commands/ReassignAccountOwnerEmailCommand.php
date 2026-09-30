<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\Exceptions\AccountOwnerEmailReassignmentMismatchException;
use HiEvents\Models\AccountUser;
use HiEvents\Models\Event;
use HiEvents\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class ReassignAccountOwnerEmailCommand extends Command
{
    private const CONFIRMATION = 'REASSIGN-OWNER-EMAIL';

    protected $signature = 'user:reassign-owner-email
        {userId : Exact user ID}
        {eventId : Event whose account ownership must match}
        {newEmail : Exact replacement email}
        {--apply : Reassign the bound user email}
        {--confirm= : Required confirmation phrase when applying}';

    protected $description = 'Reassign an exact existing account owner email without changing membership or permissions.';

    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = filter_var($this->argument('userId'), FILTER_VALIDATE_INT);
        $eventId = filter_var($this->argument('eventId'), FILTER_VALIDATE_INT);
        $newEmail = strtolower(trim((string) $this->argument('newEmail')));
        $apply = (bool) $this->option('apply');

        if ($userId === false || $userId < 1 || $eventId === false || $eventId < 1 || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Invalid owner email reassignment binding.');
            return self::INVALID;
        }

        if ($apply && !hash_equals(self::CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Apply confirmation did not match.');
            return self::INVALID;
        }

        $currentEmail = strtolower(trim((string) $this->secret('Expected current owner email')));
        if (filter_var($currentEmail, FILTER_VALIDATE_EMAIL) === false || hash_equals($currentEmail, $newEmail)) {
            $this->error('Invalid current owner email binding.');
            return self::INVALID;
        }

        try {
            $result = $this->databaseManager->transaction(
                fn (): array => $this->reassign($userId, $eventId, $currentEmail, $newEmail, $apply),
            );
        } catch (AccountOwnerEmailReassignmentMismatchException $exception) {
            $this->logger->warning('Account owner email reassignment binding mismatch.', [
                'user_id' => $userId,
                'event_id' => $eventId,
                'reason' => $exception->reason,
            ]);
            $this->error(json_encode([
                'ok' => false,
                'reason' => $exception->reason,
            ], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->logger->error('Account owner email reassignment failed closed.', [
                'user_id' => $userId,
                'event_id' => $eventId,
                'error_class' => $exception::class,
            ]);
            $this->error('Owner email reassignment stopped because of an unexpected internal error.');
            return self::FAILURE;
        }

        $this->info(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function reassign(
        int $userId,
        int $eventId,
        string $currentEmail,
        string $newEmail,
        bool $apply,
    ): array {
        if ($apply) {
            $this->databaseManager->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        }

        $eventQuery = Event::query()->whereKey($eventId);
        $userQuery = User::withTrashed()->whereKey($userId);

        if ($apply) {
            $eventQuery->lockForUpdate();
            $userQuery->lockForUpdate();
        }

        $event = $eventQuery->first();
        if ($event === null) {
            throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::EVENT_NOT_FOUND);
        }

        $user = $userQuery->first();
        if ($user === null) {
            throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::USER_NOT_FOUND);
        }

        $ownerQuery = AccountUser::withTrashed()
            ->where('account_id', $event->account_id)
            ->where('is_account_owner', true);
        $targetQuery = User::withTrashed()
            ->whereRaw('LOWER(email) = ?', [$newEmail])
            ->where('id', '<>', $userId);

        if ($apply) {
            $ownerQuery->lockForUpdate();
            $targetQuery->lockForUpdate();
        }

        $owners = $ownerQuery->get();
        if ($owners->count() !== 1) {
            throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::OWNER_COUNT_MISMATCH);
        }

        $membership = $owners->first();
        if ((int) $membership->user_id !== $userId) {
            throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::OWNER_USER_MISMATCH);
        }
        if (!hash_equals($currentEmail, strtolower($user->email))) {
            throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::CURRENT_IDENTITY_MISMATCH);
        }
        if ($targetQuery->first() !== null) {
            throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::TARGET_EMAIL_CONFLICT);
        }

        $userWasDeleted = $user->trashed();
        $membershipWasDeleted = $membership->trashed();

        if ($apply) {
            $userState = $user->getRawOriginal();
            $membershipState = $membership->getRawOriginal();

            $user->email = $newEmail;
            if (!$user->save()) {
                throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::REASSIGN_FAILED);
            }

            $userReadbackModel = User::withTrashed()->whereKey($userId)->first();
            $membershipReadbackModel = AccountUser::withTrashed()->whereKey($membership->getKey())->first();
            $targetReadback = User::withTrashed()->whereRaw('LOWER(email) = ?', [$newEmail])->get();
            if ($userReadbackModel === null
                || $membershipReadbackModel === null
                || $targetReadback->count() !== 1
                || (int) $targetReadback->first()->id !== $userId) {
                throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::READBACK_FAILED);
            }

            $userReadback = $userReadbackModel->getRawOriginal();
            $membershipReadback = $membershipReadbackModel->getRawOriginal();

            unset($userState['email'], $userState['updated_at'], $userReadback['email'], $userReadback['updated_at']);
            if (!hash_equals($newEmail, strtolower($userReadbackModel->email))
                || $userState !== $userReadback
                || $membershipState !== $membershipReadback) {
                throw new AccountOwnerEmailReassignmentMismatchException(AccountOwnerEmailReassignmentMismatchException::READBACK_FAILED);
            }

            $this->logger->critical('Account owner email reassigned through guarded command.', [
                'user_id' => $userId,
                'account_id' => $event->account_id,
                'event_id' => $eventId,
                'user_was_deleted' => $userWasDeleted,
                'membership_was_deleted' => $membershipWasDeleted,
            ]);
        }

        return [
            'ok' => true,
            'mode' => $apply ? 'apply' : 'dry-run',
            'user_id' => $userId,
            'account_id' => (int) $event->account_id,
            'event_id' => $eventId,
            'email_change_needed' => !hash_equals($currentEmail, $newEmail),
            'user_restore_needed' => $userWasDeleted,
            'membership_restore_needed' => $membershipWasDeleted,
            'membership_changed' => false,
            'permissions_changed' => false,
            'password_changed' => false,
        ];
    }
}
