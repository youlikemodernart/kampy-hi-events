<?php

namespace HiEvents\Repository\Eloquent;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class OrderPurchaseContactRepository
{
    public function lockCheckout(string $shortId): void
    {
        DB::table('orders')->where('event_id', 7)->where('short_id', $shortId)->lockForUpdate()->first(['id']);
    }

    /** Only called inside the first session-verified checkout transaction; never from receipt edits. */
    public function capture(int $orderId, int $eventId, string $email): void
    {
        if ($eventId !== 7) {
            return;
        }
        DB::table('order_purchase_contacts')->insert([
            'order_id' => $orderId,
            'email_encrypted' => Crypt::encryptString(strtolower(trim($email))),
            'created_at' => now(),
        ]);
    }

    public function email(int $orderId): ?string
    {
        $encrypted = DB::table('order_purchase_contacts')->where('order_id', $orderId)->value('email_encrypted');

        return $encrypted === null ? null : Crypt::decryptString($encrypted);
    }
}
