<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Stripe;

use HiEvents\DomainObjects\Enums\StripeWebhookAdmissionDisposition;
use Illuminate\Config\Repository;

class StripeWebhookAdmissionService
{
    public function __construct(private Repository $config) {}

    /** @param list<string> $signingPlatforms */
    public function disposition(array $signingPlatforms, ?string $stripeAccountId): StripeWebhookAdmissionDisposition
    {
        if ($signingPlatforms === [] || $stripeAccountId === null) {
            return StripeWebhookAdmissionDisposition::AMBIGUOUS;
        }

        $matches = [];
        foreach (array_unique($signingPlatforms) as $signingPlatform) {
            $accounts = $this->trustedAccounts($signingPlatform);
            if ($accounts === null) {
                return StripeWebhookAdmissionDisposition::AMBIGUOUS;
            }

            $matches[] = in_array($stripeAccountId, $accounts, true);
        }

        if ($matches === [] || count(array_unique($matches)) !== 1) {
            return StripeWebhookAdmissionDisposition::AMBIGUOUS;
        }

        return $matches[0]
            ? StripeWebhookAdmissionDisposition::LOCAL
            : StripeWebhookAdmissionDisposition::FOREIGN;
    }

    public function enforcementEnabled(): bool
    {
        return $this->config->get('services.stripe.webhook_admission.mode') === 'enforce';
    }

    public function observes(): bool
    {
        return in_array($this->config->get('services.stripe.webhook_admission.mode'), ['observe', 'enforce'], true);
    }

    /** @return list<string>|null */
    private function trustedAccounts(string $signingPlatform): ?array
    {
        $bindings = $this->config->get('services.stripe.webhook_admission.trusted_account_bindings', []);

        if (is_string($bindings)) {
            $bindings = json_decode($bindings, true);
        }

        if (! is_array($bindings) || ! isset($bindings[$signingPlatform]) || ! is_array($bindings[$signingPlatform])) {
            return null;
        }

        $accounts = $bindings[$signingPlatform];
        if ($accounts === [] || array_filter($accounts, static fn (mixed $account): bool => ! is_string($account) || $account === '') !== []) {
            return null;
        }

        return array_values($accounts);
    }
}
