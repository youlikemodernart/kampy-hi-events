<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use Exception;
use InvalidArgumentException;

class AccountOwnerRecoveryMismatchException extends Exception
{
    public const EVENT_NOT_FOUND = 'event_not_found';
    public const USER_NOT_FOUND = 'user_not_found';
    public const IDENTITY_MISMATCH = 'identity_mismatch';
    public const OWNER_COUNT_MISMATCH = 'owner_count_mismatch';
    public const OWNER_MEMBERSHIP_MISMATCH = 'owner_membership_mismatch';
    public const RESTORE_FAILED = 'restore_failed';
    public const READBACK_FAILED = 'readback_failed';

    private const REASONS = [
        self::EVENT_NOT_FOUND,
        self::USER_NOT_FOUND,
        self::IDENTITY_MISMATCH,
        self::OWNER_COUNT_MISMATCH,
        self::OWNER_MEMBERSHIP_MISMATCH,
        self::RESTORE_FAILED,
        self::READBACK_FAILED,
    ];

    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Invalid account owner recovery mismatch reason.');
        }

        parent::__construct('Account owner recovery binding mismatch.');
    }
}
