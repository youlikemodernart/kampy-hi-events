<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Exceptions\AccountOwnerRecoveryMismatchException;
use HiEvents\Models\AccountUser;
use HiEvents\Models\Event;
use HiEvents\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class RecoverAccountOwnerAccessCommand extends Command
{
    private const CONFIRMATION = 'RECOVER-OWNER-ACCESS';

    protected $signature = 'user:recover-owner-access
        {userId : Expected user ID}
        {email : Expected user email}
        {eventId : Event whose account ownership must match}
        {--apply : Restore the exact soft-deleted records}
        {--confirm= : Required confirmation phrase when applying}';

    protected $description = 'Restore an exact soft-deleted account owner without granting new permissions.';

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
        $email = strtolower(trim((string) $this->argument('email')));
        $apply = (bool) $this->option('apply');

        if ($userId === false || $userId < 1 || $eventId === false || $eventId < 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Invalid recovery binding.');
            return self::INVALID;
        }

        if ($apply && !hash_equals(self::CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Apply confirmation did not match.');
            return self::INVALID;
        }

        try {
            $result = $this->databaseManager->transaction(
                fn (): array => $this->reconcile($userId, $email, $eventId, $apply),
            );
        } catch (AccountOwnerRecoveryMismatchException $exception) {
            $this->logger->warning('Account owner recovery binding mismatch.', [
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
            $this->logger->error('Account owner recovery failed closed.', [
                'user_id' => $userId,
                'event_id' => $eventId,
                'error_class' => $exception::class,
            ]);
            $this->error('Recovery stopped because of an unexpected internal error.');
            return self::FAILURE;
        }

        $this->info(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function reconcile(int $userId, string $email, int $eventId, bool $apply): array
    {
        $eventQuery = Event::query()->whereKey($eventId);
        $userQuery = User::withTrashed()->whereKey($userId);

        if ($apply) {
            $eventQuery->lockForUpdate();
            $userQuery->lockForUpdate();
        }

        $event = $eventQuery->first();
        if ($event === null) {
            throw new AccountOwnerRecoveryMismatchException(AccountOwnerRecoveryMismatchException::EVENT_NOT_FOUND);
        }

        $user = $userQuery->first();
        if ($user === null) {
            throw new AccountOwnerRecoveryMismatchException(AccountOwnerRecoveryMismatchException::USER_NOT_FOUND);
        }

        if (!hash_equals($email, strtolower($user->email))) {
            throw new AccountOwnerRecoveryMismatchException(AccountOwnerRecoveryMismatchException::IDENTITY_MISMATCH);
        }

        $ownerQuery = AccountUser::withTrashed()
            ->where('account_id', $event->account_id)
            ->where('is_account_owner', true);

        if ($apply) {
            $ownerQuery->lockForUpdate();
        }

        $owners = $ownerQuery->get();
        if ($owners->count() !== 1) {
            throw new AccountOwnerRecoveryMismatchException(AccountOwnerRecoveryMismatchException::OWNER_COUNT_MISMATCH);
        }

        $membership = $owners->first();
        if ((int) $membership->user_id !== $userId
            || $membership->role !== Role::ADMIN->name
            || $membership->status !== UserStatus::ACTIVE->name) {
            throw new AccountOwnerRecoveryMismatchException(AccountOwnerRecoveryMismatchException::OWNER_MEMBERSHIP_MISMATCH);
        }

        $userWasDeleted = $user->trashed();
        $membershipWasDeleted = $membership->trashed();

        if ($apply) {
            if ($userWasDeleted && !$user->restore()) {
                throw new AccountOwnerRecoveryMismatchException(AccountOwnerRecoveryMismatchException::RESTORE_FAILED);
            }
            if ($membershipWasDeleted && !$membership->restore()) {
                throw new AccountOwnerRecoveryMismatchException(AccountOwnerRecoveryMismatchException::RESTORE_FAILED);
            }

            $user->refresh();
            $membership->refresh();
            if ($user->trashed() || $membership->trashed()) {
                throw new AccountOwnerRecoveryMismatchException(AccountOwnerRecoveryMismatchException::READBACK_FAILED);
            }

            $this->logger->critical('Account owner access recovered through guarded command.', [
                'user_id' => $userId,
                'account_id' => $event->account_id,
                'event_id' => $eventId,
                'user_restored' => $userWasDeleted,
                'membership_restored' => $membershipWasDeleted,
            ]);
        }

        return [
            'ok' => true,
            'mode' => $apply ? 'apply' : 'dry-run',
            'user_id' => $userId,
            'account_id' => (int) $event->account_id,
            'event_id' => $eventId,
            'user_restore_needed' => $userWasDeleted,
            'membership_restore_needed' => $membershipWasDeleted,
            'permissions_changed' => false,
        ];
    }
}
