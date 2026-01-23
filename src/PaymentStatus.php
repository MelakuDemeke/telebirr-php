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
     * @param string $tradeStatus The trade_status value from Telebirr
     * @return bool True if status indicates successful payment
     */
    public static function isSuccess(string $tradeStatus): bool
    {
        $status = strtoupper(trim($tradeStatus));
        return in_array($status, ['PAY_SUCCESS', 'SUCCESS', 'PAID']);
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
