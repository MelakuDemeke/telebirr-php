<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

use Melaku\Telebirr\Exceptions\InvalidParameterException;

/**
 * Parameter Validator
 * 
 * Validates and sanitizes parameters before sending to Telebirr API
 */
class ParameterValidator
{
    /**
     * Pattern for valid merchant order ID: alphanumeric only
     */
    private const MERCHANT_ORDER_ID_PATTERN = '/^[A-Za-z0-9]+$/';

    /**
     * Pattern for invalid title characters (must NOT contain these)
     */
    private const TITLE_INVALID_CHARS_PATTERN = '/[~`!#$%^*()\\-+=|\\/<>?;:"\\[\\]{}\\\\&]/';

    /**
     * Maximum title length
     */
    private const MAX_TITLE_LENGTH = 200;

    /**
     * Validate and sanitize merchant order ID
     * 
     * Requirements:
     * - Must match pattern: ^[A-Za-z0-9]+$ (alphanumeric only, no underscores or special chars)
     * - If null or empty, generates a new one
     * - If invalid format, sanitizes by removing invalid characters
     * 
     * @param string|null $merchantOrderId Merchant order ID to validate
     * @param bool $autoSanitize If true, automatically sanitizes invalid IDs instead of throwing
     * @return string Valid merchant order ID
     * @throws InvalidParameterException if validation fails and autoSanitize is false
     */
    public static function validateMerchantOrderId(?string $merchantOrderId, bool $autoSanitize = true): string
    {
        // Generate if empty
        if (empty($merchantOrderId)) {
            return self::generateMerchantOrderId();
        }

        // Check if valid format
        if (preg_match(self::MERCHANT_ORDER_ID_PATTERN, $merchantOrderId)) {
            return $merchantOrderId;
        }

        // Auto-sanitize if enabled
        if ($autoSanitize) {
            $sanitized = preg_replace('/[^A-Za-z0-9]/', '', $merchantOrderId);
            if (!empty($sanitized)) {
                return $sanitized;
            }
            // If sanitization resulted in empty string, generate new one
            return self::generateMerchantOrderId();
        }

        // Find invalid characters for error message
        $invalidChars = [];
        for ($i = 0; $i < strlen($merchantOrderId); $i++) {
            $char = $merchantOrderId[$i];
            if (!preg_match('/[A-Za-z0-9]/', $char)) {
                $invalidChars[$char] = true;
            }
        }
        $invalidCharsList = implode("', '", array_keys($invalidChars));

        $message = "Invalid merchant order ID: '{$merchantOrderId}'\n" .
                   "Reason: Contains invalid character(s): '{$invalidCharsList}'\n" .
                   "Required format: Alphanumeric only (A-Z, a-z, 0-9)\n" .
                   "Example: 'ORDER1234567890' or '176924750778146F8A'\n" .
                   "Current value: '{$merchantOrderId}'";

        $suggestion = "Remove all non-alphanumeric characters. " .
                     "For example, 'ORDER_123' should be 'ORDER123'";

        throw new InvalidParameterException('merchantOrderId', $merchantOrderId, $message, $suggestion);
    }

    /**
     * Validate and sanitize title
     * 
     * Requirements:
     * - Must not contain: ~`!#$%^*()\-+=|/<>?;:"[]{}\&
     * - Must not be empty after sanitization
     * - Maximum length: 200 characters
     * 
     * @param string $title Title to validate
     * @param bool $autoSanitize If true, automatically removes invalid characters
     * @return string Valid title
     * @throws InvalidParameterException if validation fails and autoSanitize is false
     */
    public static function validateTitle(string $title, bool $autoSanitize = true): string
    {
        $title = trim($title);

        // Check for invalid characters
        if (preg_match(self::TITLE_INVALID_CHARS_PATTERN, $title)) {
            if ($autoSanitize) {
                $sanitized = self::sanitizeTitle($title);
                if (!empty($sanitized)) {
                    return $sanitized;
                }
            } else {
                // Find invalid characters for error message
                preg_match_all(self::TITLE_INVALID_CHARS_PATTERN, $title, $matches);
                $invalidChars = array_unique($matches[0]);
                $invalidCharsList = implode("', '", $invalidChars);

                $message = "Invalid title: '{$title}'\n" .
                          "Reason: Contains invalid character(s): '{$invalidCharsList}'\n" .
                          "Required format: Must not contain: ~`!#$%^*()\\-+=|/<>?;:\"[]{}\\\\&\n" .
                          "Example: 'Test Order' or 'Product Purchase'\n" .
                          "Current value: '{$title}'";

                $suggestion = "Remove special characters. " .
                             "For example, 'Order #123' should be 'Order 123'";

                throw new InvalidParameterException('title', $title, $message, $suggestion);
            }
        }

        // Ensure not empty
        if (empty($title)) {
            throw new InvalidParameterException(
                'title',
                $title,
                "Title cannot be empty",
                "Provide a non-empty title for the order"
            );
        }

        // Check length
        if (strlen($title) > self::MAX_TITLE_LENGTH) {
            $title = substr($title, 0, self::MAX_TITLE_LENGTH);
        }

        return $title;
    }

    /**
     * Sanitize title by removing invalid characters
     * 
     * @param string $title Title to sanitize
     * @return string Sanitized title
     */
    public static function sanitizeTitle(string $title): string
    {
        $sanitized = preg_replace(self::TITLE_INVALID_CHARS_PATTERN, '', trim($title));
        return !empty($sanitized) ? substr($sanitized, 0, self::MAX_TITLE_LENGTH) : 'Order';
    }

    /**
     * Validate and format amount
     * 
     * Requirements:
     * - Must be numeric
     * - Must be positive (> 0)
     * - Will be formatted to 2 decimal places
     * 
     * @param string|int|float $amount Amount to validate
     * @return string Formatted amount with 2 decimal places
     * @throws InvalidParameterException if validation fails
     */
    public static function validateAmount($amount): string
    {
        if (!is_numeric($amount)) {
            $type = gettype($amount);
            $message = "Invalid amount: '{$amount}'\n" .
                      "Reason: Must be numeric, got {$type}\n" .
                      "Example: '10.00' or 10.00 or 10";
            throw new InvalidParameterException('amount', $amount, $message, "Use a numeric value like '10.00' or 10");
        }

        $floatAmount = (float) $amount;
        if ($floatAmount <= 0) {
            $message = "Invalid amount: '{$amount}'\n" .
                      "Reason: Amount must be positive (greater than 0)\n" .
                      "Example: '0.10' or '10.00'";
            throw new InvalidParameterException('amount', $amount, $message, "Use a positive number like '0.10' or '10.00'");
        }

        // Format to 2 decimal places
        return number_format($floatAmount, 2, '.', '');
    }

    /**
     * Validate URL format
     * 
     * @param string $url URL to validate
     * @param string $type URL type (for error messages): 'notifyUrl', 'redirectUrl', etc.
     * @return string Validated URL
     * @throws InvalidParameterException if URL is invalid
     */
    public static function validateUrl(string $url, string $type = 'url'): string
    {
        if (empty($url)) {
            throw new InvalidParameterException(
                $type,
                $url,
                "{$type} cannot be empty",
                "Provide a valid URL (e.g., 'https://example.com/notify.php')"
            );
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $message = "Invalid {$type}: '{$url}'\n" .
                      "Reason: Must be a valid URL format\n" .
                      "Example: 'https://example.com/notify.php'";
            throw new InvalidParameterException($type, $url, $message, "Use a valid URL format starting with http:// or https://");
        }

        return $url;
    }

    /**
     * Generate a valid merchant order ID
     * 
     * Format: timestamp + random number + random alphanumeric string
     * Ensures alphanumeric only format
     * 
     * @return string Generated merchant order ID
     */
    public static function generateMerchantOrderId(): string
    {
        return time() . rand(1000, 9999) . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 4);
    }

    /**
     * Check if merchant order ID format is valid
     * 
     * @param string $merchantOrderId Merchant order ID to check
     * @return bool True if valid format
     */
    public static function isValidMerchantOrderId(string $merchantOrderId): bool
    {
        return !empty($merchantOrderId) && preg_match(self::MERCHANT_ORDER_ID_PATTERN, $merchantOrderId);
    }
}
