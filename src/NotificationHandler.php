<?php

namespace Melaku\Telebirr;

/**
 * Notification Handler
 * 
 * Helper class for handling Telebirr server-to-server payment notifications.
 * Parses JSON, verifies signatures, and provides response helpers.
 */
class NotificationHandler
{
    /**
     * Parse notification from raw JSON input
     * 
     * @param string $rawJson Raw JSON string from php://input
     * @return array Parsed notification data
     * @throws \InvalidArgumentException if JSON is invalid
     */
    public static function parse(string $rawJson): array
    {
        $data = json_decode($rawJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }
        
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Notification data must be a JSON object');
        }
        
        return $data;
    }
    
    /**
     * Verify notification signature
     * 
     * @param array $notification Parsed notification data
     * @param Config $config Library config
     * @return bool True if signature is valid, false otherwise
     */
    public static function verify(array $notification, Config $config): bool
    {
        if (empty($notification['sign'])) {
            return false;
        }
        
        return SignatureVerifier::verify($notification, $config);
    }
    
    /**
     * Send success response to Telebirr
     * 
     * Telebirr expects a JSON response acknowledging receipt of the notification.
     * This method sends the appropriate success response.
     * 
     * @param string|null $message Optional custom message
     * @return void
     */
    public static function respondSuccess(?string $message = null): void
    {
        header('Content-Type: application/json');
        $response = ['success' => true];
        if ($message !== null) {
            $response['message'] = $message;
        }
        echo json_encode($response);
    }
    
    /**
     * Send error response to Telebirr
     * 
     * Sends an error response indicating that the notification could not be processed.
     * Telebirr may retry the notification if an error is returned.
     * 
     * @param string $message Error message
     * @param int $httpCode HTTP status code (default: 500)
     * @return void
     */
    public static function respondError(string $message, int $httpCode = 500): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
    }
    
    /**
     * Check if notification indicates successful payment
     * 
     * @param array $notification Parsed notification data
     * @return bool True if payment was successful
     */
    public static function isPaymentSuccessful(array $notification): bool
    {
        $tradeStatus = $notification['trade_status'] ?? '';
        
        if (!empty($tradeStatus)) {
            return PaymentStatus::isSuccess($tradeStatus);
        }
        
        // Fallback to legacy status field
        $status = $notification['status'] ?? '';
        if (!empty($status)) {
            return PaymentStatus::isSuccess($status);
        }
        
        return false;
    }
    
    /**
     * Extract payment information from notification
     * 
     * @param array $notification Parsed notification data
     * @return array Extracted payment information with keys:
     *   - tradeStatus: Payment status
     *   - paymentOrderId: Telebirr's payment order ID
     *   - merchantOrderId: Your merchant order ID
     *   - amount: Payment amount
     *   - currency: Currency code
     *   - timestamp: Transaction end time
     *   - notifyTime: Notification timestamp
     */
    public static function extractPaymentInfo(array $notification): array
    {
        return [
            'tradeStatus' => $notification['trade_status'] ?? '',
            'paymentOrderId' => $notification['payment_order_id'] ?? $notification['prepay_id'] ?? '',
            'merchantOrderId' => $notification['merch_order_id'] ?? '',
            'amount' => $notification['total_amount'] ?? $notification['amount'] ?? '',
            'currency' => $notification['trans_currency'] ?? 'ETB',
            'timestamp' => $notification['trans_end_time'] ?? '',
            'notifyTime' => $notification['notify_time'] ?? '',
        ];
    }
}
