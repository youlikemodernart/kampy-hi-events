<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\Models\Order;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class GvsuRegistrationBridgeServiceTest extends TestCase
{
    public function test_paid_order_predicate_blocks_cancelled_and_refunded_order_replays(): void
    {
        $service = new GvsuRegistrationBridgeService(Mockery::mock(GvsuRegistrationBridgePortalClient::class));
        $predicate = new ReflectionMethod($service, 'isCurrentPaidOrder');

        $paid = $this->order(['status' => 'COMPLETED', 'payment_status' => 'PAYMENT_RECEIVED', 'refund_status' => null, 'total_refunded' => '0.00', 'deleted_at' => null]);
        self::assertTrue($predicate->invoke($service, $paid));

        $cancelled = $this->order(['status' => 'CANCELLED', 'payment_status' => 'PAYMENT_RECEIVED', 'refund_status' => null, 'total_refunded' => '0.00', 'deleted_at' => null]);
        self::assertFalse($predicate->invoke($service, $cancelled));

        $refunded = $this->order(['status' => 'COMPLETED', 'payment_status' => 'PAYMENT_RECEIVED', 'refund_status' => 'REFUNDED', 'total_refunded' => '36.00', 'deleted_at' => null]);
        self::assertFalse($predicate->invoke($service, $refunded));
    }

    public function test_checkin_source_contract_blocks_inactive_or_deleted_attendees_before_portal_clearance(): void
    {
        $source = file_get_contents(app_path('Services/Domain/Registration/GvsuRegistrationBridgeService.php'));
        self::assertIsString($source);
        $clearance = substr($source, (int) strpos($source, 'public function assertCheckInClearance'));
        self::assertStringContainsString("\$attendee->deleted_at !== null || \$attendee->status !== 'ACTIVE'", $clearance);
        self::assertStringContainsString('throw new CannotCheckInException', $clearance);
    }

    public function test_selected_order_loads_all_active_attendees_only_after_exact_canary_admission_and_replay_stops_when_delivered(): void
    {
        $source = file_get_contents(app_path('Services/Domain/Registration/GvsuRegistrationBridgeService.php'));
        self::assertIsString($source);
        $provision = substr($source, (int) strpos($source, 'public function provisionCompletedOrder'));
        self::assertLessThan(
            strpos($provision, '$order->attendees()->withTrashed()->get()'),
            strpos($provision, 'GvsuRegistrationBridgeConfig::allowsCohort'),
        );
        self::assertStringContainsString("->filter(static fn (Attendee \$attendee): bool => \$attendee->deleted_at === null && \$attendee->status === 'ACTIVE')", $provision);
        self::assertStringContainsString('if ($allDelivered) {', $provision);
        self::assertStringContainsString('return null;', $provision);
    }

    private function order(array $attributes): Order
    {
        $order = new Order;
        foreach ($attributes as $key => $value) {
            $order->setAttribute($key, $value);
        }

        return $order;
    }
}
