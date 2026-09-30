<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

class ReassignAccountOwnerEmailCommandTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 3).'/app/Console/Commands/ReassignAccountOwnerEmailCommand.php';
        $this->assertFileExists($path);
        $this->source = file_get_contents($path);
    }

    public function test_reassignment_is_dry_run_by_default_and_requires_exact_apply_confirmation(): void
    {
        $this->assertStringContainsString('{--apply', $this->source);
        $this->assertStringContainsString("private const CONFIRMATION = 'REASSIGN-OWNER-EMAIL'", $this->source);
        $this->assertStringContainsString('hash_equals(self::CONFIRMATION', $this->source);
    }

    public function test_current_email_is_collected_through_hidden_input_and_never_returned_or_logged(): void
    {
        $this->assertStringContainsString("\$this->secret('Expected current owner email')", $this->source);
        $this->assertStringNotContainsString("'current_email' =>", $this->source);
        $this->assertStringNotContainsString("'new_email' =>", $this->source);
        $this->assertStringNotContainsString("'email' => \$currentEmail", $this->source);
        $this->assertStringNotContainsString("'email' => \$newEmail", $this->source);
    }

    public function test_reassignment_binds_event_account_sole_owner_user_and_current_identity(): void
    {
        $this->assertStringContainsString('Event::query()->whereKey($eventId)', $this->source);
        $this->assertStringContainsString('User::withTrashed()->whereKey($userId)', $this->source);
        $this->assertStringContainsString("->where('account_id', $event->account_id)", $this->source);
        $this->assertStringContainsString("->where('is_account_owner', true)", $this->source);
        $this->assertStringContainsString('$owners->count() !== 1', $this->source);
        $this->assertStringContainsString('(int) $membership->user_id !== $userId', $this->source);
        $this->assertStringContainsString('hash_equals($currentEmail, strtolower($user->email))', $this->source);
    }

    public function test_target_email_must_be_unused_including_soft_deleted_users(): void
    {
        $this->assertStringContainsString('User::withTrashed()', $this->source);
        $this->assertStringContainsString("->whereRaw('LOWER(email) = ?', [$newEmail])", $this->source);
        $this->assertStringContainsString('AccountOwnerEmailReassignmentMismatchException::TARGET_EMAIL_CONFLICT', $this->source);
    }

    public function test_apply_changes_only_email_and_timestamp_and_requires_full_readback(): void
    {
        $this->assertSame(1, substr_count($this->source, '$user->email = $newEmail'));
        $this->assertSame(1, substr_count($this->source, '$user->save()'));
        $this->assertStringContainsString("unset(\$userState['email'], \$userState['updated_at']", $this->source);
        $this->assertStringContainsString('$userState !== $userReadback', $this->source);
        $this->assertStringContainsString('$membershipState !== $membershipReadback', $this->source);
        $this->assertStringNotContainsString('->restore()', $this->source);
        $this->assertStringNotContainsString('->update(', $this->source);
    }

    public function test_apply_uses_transaction_and_row_locks_without_changing_authority(): void
    {
        $this->assertStringContainsString('$this->databaseManager->transaction(', $this->source);
        $this->assertStringContainsString("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE", $this->source);
        $this->assertGreaterThanOrEqual(4, substr_count($this->source, 'lockForUpdate()'));
        $this->assertStringContainsString('$targetReadback->count() !== 1', $this->source);
        $this->assertStringContainsString('(int) $targetReadback->first()->id !== $userId', $this->source);
        $this->assertStringContainsString("'membership_changed' => false", $this->source);
        $this->assertStringContainsString("'permissions_changed' => false", $this->source);
        $this->assertStringContainsString("'password_changed' => false", $this->source);
    }
}
