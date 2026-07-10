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
     * Parse and verify return URL parameters.
     *
     * SECURITY: return URL parameters arrive through the user's browser and are
     * trivially spoofable. This method therefore FAILS CLOSED — a missing or
     * invalid signature throws. Even with a valid signature, treat the result as
     * a hint only: for anything that moves money or fulfils an order, confirm the
     * real status server-to-server with {@see Telebirr::queryOrder()} rather than
     * trusting the redirect. The signature proves the params were not tampered
     * with in transit; it does not prove the payment actually succeeded.
     *
     * This method:
     * 1. Requires and verifies the signature to ensure data authenticity
     * 2. Parses and normalizes parameters
     * 3. Extracts payment information
     *
     * @param array $params $_GET parameters from return URL
     * @param Config $config Library config (must have a public key available)
     * @return array Parsed and verified payment data with keys:
     *   - tradeStatus: Payment status (e.g., 'PAY_SUCCESS')
     *   - paymentOrderId: Telebirr's payment order ID
     *   - merchantOrderId: Your merchant order ID
     *   - amount: Payment amount
     *   - currency: Currency code (typically 'ETB')
     *   - isSuccess: Boolean indicating if payment was successful
     *   - timestamp: Transaction end time
     *   - raw: All original parameters
     * @throws \RuntimeException if the signature is missing or invalid
     */
    public static function handle(array $params, Config $config): array
    {
        // Verification is mandatory — an unsigned return must never be trusted.
        if (empty($params['sign'])) {
            throw new \RuntimeException(
                'Missing signature on return URL - refusing to trust unsigned payment data. '
                . 'Confirm the order server-to-server via Telebirr::queryOrder().'
            );
        }

        if (!SignatureVerifier::verify($params, $config)) {
            throw new \RuntimeException('Invalid signature - payment data may be tampered with');
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
     * Check if payment was successful based on return URL parameters.
     *
     * Fails closed: success is returned ONLY when an explicit success status is
     * present. The absence of an error code is NOT treated as success — a crafted
     * return URL carrying only a merch_order_id must never read as paid.
     *
     * @param array $params Return URL parameters
     * @return bool True only if an explicit success status is present
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

        // No explicit status => not a confirmed success.
        return false;
    }
}
