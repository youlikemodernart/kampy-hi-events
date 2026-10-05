<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class RespondentCheckoutFixture
{
    public static function migrate(): void
    {
        RespondentConfirmationFixture::configure();
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new \RuntimeException(Artisan::output());
        }
    }

    public static function seed(): void
    {
        // Owned disposable database only; identities kept deterministic for browser assertions.
        RespondentConfirmationFixture::configure();
        DB::statement('TRUNCATE accounts, users, events, orders, order_purchase_contacts, respondent_confirmation_destinations RESTART IDENTITY CASCADE');
        $times = ['created_at' => now(), 'updated_at' => now()];
        self::insert('accounts', ['id' => 1, 'name' => 'Synthetic account', 'email' => 'account@example.test', 'short_id' => 'synthetic', 'timezone' => 'America/Detroit'] + $times);
        self::insert('users', ['id' => 1, 'first_name' => 'Synthetic', 'last_name' => 'Operator', 'email' => 'operator@example.test', 'password' => 'not-a-login', 'timezone' => 'America/Detroit'] + $times);
        self::insert('organizers', ['id' => 1, 'account_id' => 1, 'name' => 'Synthetic organizer', 'email' => 'organizer@example.test', 'timezone' => 'America/Detroit'] + $times);
        self::insert('events', ['id' => 7, 'account_id' => 1, 'user_id' => 1, 'organizer_id' => 1, 'title' => 'Synthetic checkout', 'short_id' => 'synthetic-event', 'status' => 'LIVE', 'timezone' => 'America/Detroit', 'start_date' => '2099-10-18 12:00:00', 'end_date' => '2099-10-18 16:00:00'] + $times);
        DB::table('event_settings')->insert(['event_id' => 7, 'allow_attendee_self_edit' => true, 'attendee_details_collection_method' => 'PER_TICKET', 'enable_invoicing' => false, 'require_billing_address' => false] + $times);
        $category = DB::table('product_categories')->insertGetId(['event_id' => 7, 'name' => 'Synthetic admission', 'order' => 1] + $times);
        self::insert('products', ['id' => 1, 'event_id' => 7, 'title' => 'Synthetic admission', 'type' => 'PAID', 'product_type' => 'TICKET', 'order' => 1, 'product_category_id' => $category] + $times);
        self::insert('product_prices', ['id' => 1, 'product_id' => 1, 'price' => 18, 'initial_quantity_available' => 100, 'quantity_available' => 100] + $times);
        foreach ([11, 12] as $id) {
            self::insert('orders', ['id' => $id, 'event_id' => 7, 'short_id' => 'order_'.$id, 'public_id' => 'synthetic_order_'.$id, 'status' => 'RESERVED', 'currency' => 'USD', 'total_gross' => 36, 'total_before_additions' => 36, 'session_id' => 'synthetic-session-'.$id, 'reserved_until' => now()->addHour()] + $times);
            DB::table('order_items')->insert(['order_id' => $id, 'product_id' => 1, 'product_price_id' => 1, 'quantity' => 2, 'price' => 18, 'total_before_additions' => 36, 'total_gross' => 36, 'item_name' => 'Synthetic admission']);
        }
    }

    private static function insert(string $table, array $row): void
    {
        $columns = implode(', ', array_map(fn ($column) => '"'.$column.'"', array_keys($row)));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        DB::insert('INSERT INTO "'.$table.'" ('.$columns.') OVERRIDING SYSTEM VALUE VALUES ('.$placeholders.')', array_values($row));
    }

    public static function contactPayload(): array
    {
        return ['order' => ['first_name' => 'Synthetic', 'last_name' => 'Buyer', 'email' => 'Buyer@Example.test', 'email_confirmation' => 'Buyer@Example.test'], 'products' => array_map(fn ($n) => ['product_id' => 1, 'product_price_id' => 1, 'first_name' => 'Invented', 'last_name' => 'Attendee '.$n, 'email' => 'attendee'.$n.'@example.test', 'email_confirmation' => 'attendee'.$n.'@example.test'], [1, 2])];
    }

    public static function paid(int $id): void
    {
        // Explicit synthetic payment seam: no Stripe call/webhook or financial effect replay.
        DB::table('orders')->where('id', $id)->update(['status' => 'COMPLETED', 'payment_status' => 'PAYMENT_RECEIVED']);
        DB::table('attendees')->where('order_id', $id)->update(['status' => 'ACTIVE']);
    }

    public static function respondents(int $id): array
    {
        $ids = DB::table('attendees')->where('order_id', $id)->orderBy('id')->pluck('id');

        return [['attendee_id' => $ids[0], 'route' => 'adult', 'respondent_name' => '', 'email' => 'adult@example.test'], ['attendee_id' => $ids[1], 'route' => 'guardian', 'respondent_name' => 'Invented Guardian', 'email' => 'guardian@example.test']];
    }
}
