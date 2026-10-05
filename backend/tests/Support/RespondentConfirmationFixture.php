<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RespondentConfirmationFixture
{
    public static function configure(): void
    {
        if (getenv('KAMP_RESPONDENT_DISPOSABLE') !== '1' || getenv('DB_HOST') !== '127.0.0.1' || ! str_starts_with((string) getenv('DB_DATABASE'), 'respondent_test_')) {
            throw new \RuntimeException('Disposable loopback test database required');
        }
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => getenv('DB_PORT'), 'database' => getenv('DB_DATABASE'), 'username' => getenv('DB_USERNAME'), 'password' => getenv('DB_PASSWORD'), 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable']);
        DB::purge('pgsql');
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('jwt.secret', str_repeat('j', 32));
        config()->set('services.gvsu_registration_bridge.mode', 'live');
        config()->set('services.gvsu_registration_bridge.email_hmac_current_key', str_repeat('h', 43));
        config()->set('respondent-confirmation.enabled', true);
        config()->set('mail.default', 'array');
        config()->set('mail.mailers.array', ['transport' => 'array']);
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
    }

    public static function reset(): void
    {
        Schema::dropAllTables();
        DB::statement('DROP FUNCTION IF EXISTS reject_order_purchase_contact_update()');
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->timestamp('end_date');
            $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('event_id');
            $t->string('short_id')->unique();
            $t->string('status');
            $t->string('payment_status');
            $t->string('refund_status')->nullable();
            $t->decimal('total_refunded')->default(0);
            $t->string('email');
            $t->softDeletes();
        });
        Schema::create('attendees', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('event_id');
            $t->foreignId('order_id')->constrained();
            $t->string('status');
            $t->string('public_id');
            $t->string('first_name');
            $t->string('last_name');
            $t->softDeletes();
        });
        foreach (['2026_09_22_000001_create_gvsu_registration_assignments_table.php', '2026_07_25_000005_create_order_effect_outbox_table.php', '2026_10_05_000001_create_respondent_confirmation_challenges.php', '2026_10_05_000002_create_order_purchase_contacts.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        DB::table('events')->insert(['id' => 7, 'status' => 'LIVE', 'end_date' => '2099-10-18 16:00:00']);
        foreach ([11, 12] as $id) {
            DB::table('orders')->insert(['id' => $id, 'event_id' => 7, 'short_id' => 'order_'.$id, 'status' => 'COMPLETED', 'payment_status' => 'PAYMENT_RECEIVED', 'email' => 'buyer@example.test']);
            (new \HiEvents\Repository\Eloquent\OrderPurchaseContactRepository)->capture($id, 7, 'buyer@example.test');
            foreach ([1, 2] as $n) {
                DB::table('attendees')->insert(['id' => $id * 10 + $n, 'event_id' => 7, 'order_id' => $id, 'status' => 'ACTIVE', 'public_id' => 'invented_'.$id.'_'.$n, 'first_name' => 'Invented', 'last_name' => 'Attendee '.$n]);
            }
        }
    }

    public static function payload(int $order = 11): array
    {
        return [['attendee_id' => $order * 10 + 1, 'route' => 'adult', 'respondent_name' => '', 'email' => 'adult@example.test'], ['attendee_id' => $order * 10 + 2, 'route' => 'guardian', 'respondent_name' => 'Invented Guardian', 'email' => 'guardian@example.test']];
    }
}
