<?php

namespace Melaku\Telebirr;

/**
 * Return URL Handler
 * 
 * Helper class for handling Telebirr return URL parameters.
 * Parses, verifies signatures, and extracts payment information.
 */
class ReturnUrlHandler
{
    /**
     * Parse and verify return URL parameters
     * 
     * This method:
     * 1. Verifies the signature to ensure data authenticity
     * 2. Parses and normalizes parameters
     * 3. Extracts payment information
     * 
     * @param array $params $_GET parameters from return URL
     * @param Config $config Library config
     * @return array Parsed and verified payment data with keys:
     *   - tradeStatus: Payment status (e.g., 'PAY_SUCCESS')
     *   - paymentOrderId: Telebirr's payment order ID
     *   - merchantOrderId: Your merchant order ID
     *   - amount: Payment amount
     *   - currency: Currency code (typically 'ETB')
     *   - isSuccess: Boolean indicating if payment was successful
     *   - timestamp: Transaction end time
     *   - raw: All original parameters
     * @throws \RuntimeException if signature verification fails
     */
    public static function handle(array $params, Config $config): array
    {
        // Verify signature if present
        if (!empty($params['sign'])) {
            if (!SignatureVerifier::verify($params, $config)) {
                throw new \RuntimeException('Invalid signature - payment data may be tampered with');
            }
        }
        
        // Parse and normalize parameters
        $tradeStatus = $params['trade_status'] ?? '';
        $paymentOrderId = $params['payment_order_id'] ?? '';
        $merchantOrderId = $params['merch_order_id'] ?? '';
        $amount = $params['total_amount'] ?? '';
        $currency = $params['trans_currency'] ?? 'ETB';
        $timestamp = $params['trans_end_time'] ?? '';
        
        return [
            'tradeStatus' => $tradeStatus,
            'paymentOrderId' => $paymentOrderId,
            'merchantOrderId' => $merchantOrderId,
            'amount' => $amount,
            'currency' => $currency,
            'isSuccess' => self::isPaymentSuccessful($params),
            'timestamp' => $timestamp,
            'raw' => $params,
        ];
    }
    
    /**
     * Check if payment was successful based on return URL parameters
     * 
     * @param array $params Return URL parameters
     * @return bool True if payment was successful
     */
    public static function isPaymentSuccessful(array $params): bool
    {
        $tradeStatus = $params['trade_status'] ?? '';
        
        // Check primary status field
        if (!empty($tradeStatus)) {
            return PaymentStatus::isSuccess($tradeStatus);
        }
        
        // Fallback to legacy status field
        $status = $params['status'] ?? '';
        if (!empty($status)) {
            return PaymentStatus::isSuccess($status);
        }
        
        // If no status but also no error, might be success (legacy behavior)
        $errorCode = $params['errorCode'] ?? '';
        return empty($errorCode);
    }
}
