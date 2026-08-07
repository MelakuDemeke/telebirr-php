<?php

namespace Melaku\Telebirr;

use Melaku\Telebirr\Exceptions\TelebirrException;

/**
 * Notification Handler
 *
 * Helper class for handling Telebirr server-to-server payment notifications.
 * Parses JSON, verifies signatures, and provides response helpers.
 *
 * The notify leg does not speak quite the same dialect as the return URL or
 * queryOrder, and every difference fails the same silent way — as a payload
 * that verifies but never fulfils. The three that bite:
 *
 *  - `trade_status` is `Completed`, not `PAY_SUCCESS` (see {@see PaymentStatus}).
 *  - the body is sometimes wrapped in a `data` envelope, which hides both the
 *    order id and the signature from every helper here (see {@see unwrap}).
 *  - `notify_time` / `trans_end_time` are epoch **milliseconds**, where the
 *    return URL sends `Y-m-d H:i:s` strings (see {@see toUnixSeconds}).
 */
class NotificationHandler
{
    /**
     * Parse notification from raw JSON input
     *
     * The `data` envelope is unwrapped here, so everything downstream —
     * {@see verify}, {@see isPaymentSuccessful}, {@see extractPaymentInfo} —
     * sees the flat payload it expects.
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

        return self::unwrap($data);
    }

    /**
     * Unwrap the `data` envelope, if there is one.
     *
     * Both shapes are tolerated rather than the wrapper being assumed: flat
     * bodies are documented and observed in the field, so anything already flat
     * has to pass through untouched.
     *
     * Left wrapped, `merch_order_id` and `sign` are both absent as far as the
     * helpers here are concerned — the signature check refuses the callback and
     * the reference matches no order.
     *
     * @param array $notification Parsed notification data, wrapped or flat
     * @return array The flat notification payload
     */
    public static function unwrap(array $notification): array
    {
        $inner = isset($notification['data']) ? $notification['data'] : null;

        // `merch_order_id` is the marker for a genuine envelope. Unwrapping on
        // the mere presence of a `data` key would mangle a flat payload that
        // happened to carry an unrelated one.
        if (is_array($inner) && array_key_exists('merch_order_id', $inner)) {
            return $inner;
        }

        return $notification;
    }

    /**
     * Parse, verify and extract a notification in one call — the safe default.
     *
     * Fails closed: an unsigned or badly signed body throws rather than
     * returning something that looks usable. Mirrors {@see ReturnUrlHandler::handle}.
     *
     * As with the return leg, a valid signature proves the payload was not
     * tampered with in transit — not that the payment succeeded. For anything
     * that fulfils an order, confirm with {@see Telebirr::getOrderStatus()}.
     *
     * @param string $rawJson Raw JSON body from php://input
     * @param Config $config Library config (must have a public key available)
     * @return array {@see extractPaymentInfo} plus an `isSuccess` boolean
     * @throws \InvalidArgumentException if the body is not valid JSON
     * @throws TelebirrException if the signature is missing or invalid
     */
    public static function handle(string $rawJson, Config $config): array
    {
        $notification = self::parse($rawJson);

        if (!self::verify($notification, $config)) {
            throw new TelebirrException(
                'Invalid notification signature - refusing to trust the payload. '
                . 'Confirm the order server-to-server via Telebirr::getOrderStatus().'
            );
        }

        $info = self::extractPaymentInfo($notification);
        $info['isSuccess'] = self::isPaymentSuccessful($notification);

        return $info;
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
     * Build the success acknowledgement for Telebirr.
     *
     * Returns a {@see NotificationResponse} value object; it does NOT emit
     * headers or output. In a framework, turn it into a proper Response so the
     * response lifecycle is respected (avoiding "headers already sent"). In a
     * bare PHP endpoint, call `->send()` on the returned object.
     *
     * @param string|null $message Optional custom message
     */
    public static function respondSuccess(?string $message = null): NotificationResponse
    {
        $response = ['success' => true];
        if ($message !== null) {
            $response['message'] = $message;
        }

        return new NotificationResponse(200, (string) json_encode($response));
    }

    /**
     * Build an error acknowledgement for Telebirr.
     *
     * Returns a {@see NotificationResponse} value object (no header()/echo).
     * Telebirr may retry the notification when it receives an error status.
     *
     * @param string $message Error message
     * @param int $httpCode HTTP status code (default: 500)
     */
    public static function respondError(string $message, int $httpCode = 500): NotificationResponse
    {
        return new NotificationResponse($httpCode, (string) json_encode([
            'success' => false,
            'message' => $message
        ]));
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
     *   - tradeStatus: Payment status (`Completed` on this leg)
     *   - paymentOrderId: Telebirr's payment order ID
     *   - merchantOrderId: Your merchant order ID
     *   - transId: Telebirr's short transaction ID — the one the customer sees
     *     in their SMS receipt, and the one they will quote to your support desk
     *   - merchCode: The merchant code the payment was made against
     *   - appId: The app id the payment was made against
     *   - notifyUrl: The callback URL Telebirr echoes back — worth logging when
     *     diagnosing notifications that never arrive
     *   - amount: Payment amount
     *   - currency: Currency code
     *   - timestamp: Transaction end time, verbatim
     *   - notifyTime: Notification timestamp, verbatim
     *   - timestampUnix: Transaction end time as Unix seconds, or null
     *   - notifyTimeUnix: Notification time as Unix seconds, or null
     *   - raw: The full notification payload
     */
    public static function extractPaymentInfo(array $notification): array
    {
        return [
            'tradeStatus' => $notification['trade_status'] ?? '',
            'paymentOrderId' => $notification['payment_order_id'] ?? $notification['prepay_id'] ?? '',
            'merchantOrderId' => $notification['merch_order_id'] ?? '',
            'transId' => $notification['transId'] ?? $notification['trans_id'] ?? '',
            'merchCode' => $notification['merch_code'] ?? '',
            'appId' => $notification['appid'] ?? '',
            'notifyUrl' => $notification['notify_url'] ?? '',
            'amount' => $notification['total_amount'] ?? $notification['amount'] ?? '',
            'currency' => $notification['trans_currency'] ?? 'ETB',
            'timestamp' => $notification['trans_end_time'] ?? '',
            'notifyTime' => $notification['notify_time'] ?? '',
            'timestampUnix' => self::toUnixSeconds($notification['trans_end_time'] ?? null),
            'notifyTimeUnix' => self::toUnixSeconds($notification['notify_time'] ?? null),
            'raw' => $notification,
        ];
    }

    /**
     * Normalize a Telebirr time field to Unix seconds.
     *
     * The notify leg sends epoch milliseconds (`1784756474676`); queryOrder does
     * the same; the return URL sends a formatted `Y-m-d H:i:s` string instead.
     * Anything non-numeric therefore yields null rather than a guess — the
     * formatted strings carry no timezone, and assuming one here would quietly
     * shift every timestamp by three hours.
     *
     * @param mixed $value Raw `trans_end_time` / `notify_time` value
     * @return int|null Unix seconds, or null if the value is absent or not numeric
     */
    public static function toUnixSeconds($value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        // 99999999999 seconds is the year 5138, so anything larger is milliseconds.
        return $number > 99999999999 ? intdiv($number, 1000) : $number;
    }
}
