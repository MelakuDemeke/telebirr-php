<?php

namespace Melaku\Telebirr;

/**
 * Payment Status Helper
 * 
 * Provides utility methods for checking payment status values
 * from Telebirr return URLs and notifications.
 */
class PaymentStatus
{
    /**
     * Check if trade_status indicates success
     *
     * The three channels do not agree on the word for "paid". The return URL and
     * queryOrder both say `PAY_SUCCESS`; the server-to-server notification says
     * `Completed`. Verified against a live notify body from a production merchant
     * (merchant code and order redacted, 2026-07-22).
     *
     * That disagreement is worth stating plainly because of how it fails: a
     * notification whose signature verifies and whose payment is genuinely
     * complete reads as unsuccessful, so fulfillment silently never runs. There
     * is no error, no exception and no log line — it looks exactly like a
     * callback that never arrived.
     *
     * @param string $tradeStatus The trade_status value from Telebirr
     * @return bool True if status indicates successful payment
     */
    public static function isSuccess(string $tradeStatus): bool
    {
        $status = strtoupper(trim($tradeStatus));
        return in_array($status, ['PAY_SUCCESS', 'SUCCESS', 'PAID', 'COMPLETED']);
    }
    
    /**
     * Check if trade_status indicates failure
     * 
     * @param string $tradeStatus The trade_status value from Telebirr
     * @return bool True if status indicates failed payment
     */
    public static function isFailure(string $tradeStatus): bool
    {
        $status = strtoupper(trim($tradeStatus));
        return in_array($status, ['PAY_FAILED', 'FAILED']);
    }
    
    /**
     * Check if trade_status indicates cancellation
     * 
     * @param string $tradeStatus The trade_status value from Telebirr
     * @return bool True if status indicates cancelled payment
     */
    public static function isCancelled(string $tradeStatus): bool
    {
        $status = strtoupper(trim($tradeStatus));
        return in_array($status, ['PAY_CANCEL', 'CANCEL', 'CANCELLED']);
    }
}
