<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Stripe;

use HiEvents\DomainObjects\Enums\StripeWebhookAdmissionDisposition;
use HiEvents\Services\Infrastructure\Stripe\StripeWebhookAdmissionService;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StripeWebhookAdmissionServiceTest extends TestCase
{
    #[DataProvider('dispositions')]
    public function test_requires_a_configured_platform_account_contradiction_to_classify_foreign(
        array|string $bindings,
        array $platforms,
        ?string $account,
        StripeWebhookAdmissionDisposition $expected,
    ): void {
        $service = new StripeWebhookAdmissionService(new Repository([
            'services' => ['stripe' => ['webhook_admission' => [
                'mode' => 'enforce',
                'trusted_account_bindings' => $bindings,
            ]]],
        ]));

        self::assertSame($expected, $service->disposition($platforms, $account));
    }

    public static function dispositions(): array
    {
        return [
            'matching platform and account is local' => [['default' => ['acct_kamp']], ['default'], 'acct_kamp', StripeWebhookAdmissionDisposition::LOCAL],
            'configured account contradiction is foreign' => [['default' => ['acct_kamp']], ['default'], 'acct_foreign', StripeWebhookAdmissionDisposition::FOREIGN],
            'unknown signing platform is ambiguous' => [['default' => ['acct_kamp']], ['ca'], 'acct_foreign', StripeWebhookAdmissionDisposition::AMBIGUOUS],
            'missing event account is ambiguous' => [['default' => ['acct_kamp']], ['default'], null, StripeWebhookAdmissionDisposition::AMBIGUOUS],
            'unset bindings are ambiguous' => [[], ['default'], 'acct_foreign', StripeWebhookAdmissionDisposition::AMBIGUOUS],
            'malformed bindings are ambiguous' => [['default' => ['acct_kamp', 9]], ['default'], 'acct_foreign', StripeWebhookAdmissionDisposition::AMBIGUOUS],
            'duplicate matches agree before foreign acknowledgement' => [['default' => ['acct_kamp'], 'ca' => ['acct_kamp']], ['default', 'ca'], 'acct_foreign', StripeWebhookAdmissionDisposition::FOREIGN],
            'duplicate matches with mixed agreement are ambiguous' => [['default' => ['acct_kamp'], 'ca' => ['acct_foreign']], ['default', 'ca'], 'acct_kamp', StripeWebhookAdmissionDisposition::AMBIGUOUS],
            'duplicate matches with an unknown binding are ambiguous' => [['default' => ['acct_kamp']], ['default', 'ca'], 'acct_foreign', StripeWebhookAdmissionDisposition::AMBIGUOUS],
            'JSON bindings are supported' => ['{"default":["acct_kamp"]}', ['default'], 'acct_foreign', StripeWebhookAdmissionDisposition::FOREIGN],
        ];
    }

    #[DataProvider('modes')]
    public function test_enforcement_and_observation_modes(string $mode, bool $observes, bool $enforces): void
    {
        $service = new StripeWebhookAdmissionService(new Repository([
            'services' => ['stripe' => ['webhook_admission' => ['mode' => $mode]]],
        ]));

        self::assertSame($observes, $service->observes());
        self::assertSame($enforces, $service->enforcementEnabled());
    }

    public static function modes(): array
    {
        return [
            'off' => ['off', false, false],
            'observe' => ['observe', true, false],
            'enforce' => ['enforce', true, true],
            'unknown is fail safe' => ['invalid', false, false],
        ];
    }
}
