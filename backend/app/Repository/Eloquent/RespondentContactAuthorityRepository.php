<?php

namespace HiEvents\Repository\Eloquent;

use Illuminate\Support\Facades\DB;

final class RespondentContactAuthorityRepository
{
    public function resolve(int $orderId): ?array
    {
        $anchor = DB::table('order_purchase_contacts')->where('order_id', $orderId)->first();
        $historicalExists = DB::table('order_receipt_recovery_evidence')->where('order_id', $orderId)->exists();
        if ($anchor && $historicalExists) {
            return null;
        }
        if (! $anchor) {
            return (new HistoricalReceiptRecoveryRepository)->authority($orderId);
        }
        $email = (new OrderPurchaseContactRepository)->email($orderId);

        return ['type' => 'checkout_anchor_v1', 'id' => (int) $anchor->id, 'commitment' => hash('sha256', $anchor->email_encrypted), 'email' => $email];
    }
}
