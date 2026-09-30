<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

class RecoverAccountOwnerAccessCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 3).'/app/Console/Commands/RecoverAccountOwnerAccessCommand.php';
        $this->assertFileExists($path);
        $this->source = file_get_contents($path);
    }

    public function test_recovery_is_dry_run_by_default_and_requires_an_exact_apply_confirmation(): void
    {
        $this->assertStringContainsString("{--apply", $this->source);
        $this->assertStringContainsString("private const CONFIRMATION = 'RECOVER-OWNER-ACCESS'", $this->source);
        $this->assertStringContainsString('hash_equals(self::CONFIRMATION', $this->source);
    }

    public function test_recovery_binds_identity_event_account_and_existing_owner_membership(): void
    {
        $this->assertStringContainsString('Event::query()->whereKey($eventId)', $this->source);
        $this->assertStringContainsString('User::withTrashed()->whereKey($userId)', $this->source);
        $this->assertStringContainsString('hash_equals($email, strtolower($user->email))', $this->source);
        $this->assertStringContainsString("->where('account_id', $event->account_id)", $this->source);
        $this->assertStringContainsString("->where('is_account_owner', true)", $this->source);
        $this->assertStringContainsString('$owners->count() !== 1', $this->source);
        $this->assertStringContainsString('(int) $membership->user_id !== $userId', $this->source);
    }

    public function test_dry_run_reports_only_allowlisted_mismatch_categories(): void
    {
        $exceptionPath = dirname(__DIR__, 3).'/app/Exceptions/AccountOwnerRecoveryMismatchException.php';
        $this->assertFileExists($exceptionPath);
        $exception = file_get_contents($exceptionPath);

        foreach ([
            'event_not_found',
            'user_not_found',
            'identity_mismatch',
            'owner_count_mismatch',
            'owner_membership_mismatch',
            'restore_failed',
            'readback_failed',
        ] as $reason) {
            $this->assertStringContainsString("'{$reason}'", $exception);
        }

        $this->assertStringContainsString("'reason' => \$exception->reason", $this->source);
        $this->assertStringNotContainsString("'email' =>", $this->source);
    }

    public function test_recovery_refuses_permission_or_status_changes(): void
    {
        $this->assertStringContainsString('$membership->role !== Role::ADMIN->name', $this->source);
        $this->assertStringContainsString('$membership->status !== UserStatus::ACTIVE->name', $this->source);
        $this->assertStringNotContainsString('->update(', $this->source);
        $this->assertStringContainsString("'permissions_changed' => false", $this->source);
    }

    public function test_apply_uses_a_transaction_row_locks_restore_only_and_readback(): void
    {
        $this->assertStringContainsString('$this->databaseManager->transaction(', $this->source);
        $this->assertGreaterThanOrEqual(3, substr_count($this->source, 'lockForUpdate()'));
        $this->assertSame(2, substr_count($this->source, '->restore()'));
        $this->assertSame(2, substr_count($this->source, '->refresh()'));
        $this->assertStringContainsString('$user->trashed() || $membership->trashed()', $this->source);
    }

    public function test_owner_email_reveal_requires_a_separately_confirmed_dry_run(): void
    {
        $this->assertStringContainsString("private const REVEAL_CONFIRMATION = 'REVEAL-OWNER-EMAIL'", $this->source);
        $this->assertStringContainsString('{--reveal-owner-email', $this->source);
        $this->assertStringContainsString('$apply || !hash_equals(self::REVEAL_CONFIRMATION', $this->source);
        $this->assertStringContainsString("'stored_email' => \$user->email", $this->source);
        $this->assertStringNotContainsString("'stored_email' => \$exception", $this->source);
    }

    public function test_command_does_not_change_passwords(): void
    {
        $this->assertStringNotContainsString('password', strtolower($this->source));
    }
}
