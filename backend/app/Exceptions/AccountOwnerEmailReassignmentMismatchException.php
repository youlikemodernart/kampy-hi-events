<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;
use InvalidArgumentException;

class AccountOwnerEmailReassignmentMismatchException extends Exception
{
    public const EVENT_NOT_FOUND = 'event_not_found';
    public const USER_NOT_FOUND = 'user_not_found';
    public const OWNER_COUNT_MISMATCH = 'owner_count_mismatch';
    public const OWNER_USER_MISMATCH = 'owner_user_mismatch';
    public const CURRENT_IDENTITY_MISMATCH = 'current_identity_mismatch';
    public const TARGET_EMAIL_CONFLICT = 'target_email_conflict';
    public const REASSIGN_FAILED = 'reassign_failed';
    public const READBACK_FAILED = 'readback_failed';

    private const REASONS = [
        self::EVENT_NOT_FOUND,
        self::USER_NOT_FOUND,
        self::OWNER_COUNT_MISMATCH,
        self::OWNER_USER_MISMATCH,
        self::CURRENT_IDENTITY_MISMATCH,
        self::TARGET_EMAIL_CONFLICT,
        self::REASSIGN_FAILED,
        self::READBACK_FAILED,
    ];

    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Invalid account owner email reassignment mismatch reason.');
        }

        parent::__construct('Account owner email reassignment binding mismatch.');
    }
}
