# Telebirr Library Improvements

This document outlines the improvements made to the Telebirr PHP library based on real-world usage and testing.

## Summary of Improvements

All 7 improvement areas from the plan have been implemented:

1. ✅ **Parameter Validation** - Complete
2. ✅ **Better Error Messages** - Complete
3. ✅ **Built-in Sanitization** - Complete
4. ✅ **Configuration Validation** - Complete
5. ✅ **Helper Methods** - Complete
6. ✅ **Type Hints and Return Types** - Complete
7. ✅ **Logging Support** - Complete

## New Files Created

### Exception Classes
- `src/Exceptions/InvalidParameterException.php` - Custom exception for parameter validation errors
- `src/Exceptions/ConfigurationException.php` - Custom exception for configuration validation errors

### Validation
- `src/ParameterValidator.php` - Comprehensive parameter validation and sanitization class

### Logging
- `src/Logger/LoggerInterface.php` - Simple logger interface (PSR-3 compatible)
- `src/Logger/NullLogger.php` - No-op logger implementation

## Enhanced Files

### `src/Telebirr.php`

**Added:**
- Logger support with `setLogger()` method
- Parameter validation in `createOrder()`, `createCheckoutUrl()`, `refundOrder()`, `queryOrder()`
- Automatic sanitization of title and merchant order ID
- Better error messages with context and suggestions
- Helper methods: `generateMerchantOrderId()`, `sanitizeTitle()`, `formatAmount()`, `isValidMerchantOrderId()`
- Logging for all API requests and responses
- Error formatting methods: `formatApiError()`, `formatApiErrorResponse()`

**Key Changes:**
- `createOrder()` now validates and sanitizes all parameters automatically
- `createCheckoutUrl()` validates parameters before API calls
- `refundOrder()` validates refund amount and order IDs
- `queryOrder()` validates merchant order ID if provided
- All methods log requests/responses when logger is provided

### `src/Config.php`

**Added:**
- `validate()` method - Validates configuration completeness and correctness
- `isComplete()` method - Checks if all required fields are set
- `getMissingFields()` method - Returns array of missing required fields
- `validateEnvironment()` method - Validates and returns environment

**Enhanced:**
- Added `declare(strict_types=1)` for type safety
- Added proper return type hints to all methods

### `composer.json`

**Updated:**
- Added `suggest` section for optional `psr/log` package

## Usage Examples

### Parameter Validation (Automatic)

```php
use Melaku\Telebirr\Config;
use Melaku\Telebirr\Telebirr;

$config = Config::forTest([...]);
$client = new Telebirr($config);

// Title with invalid characters - automatically sanitized
$checkoutUrl = $client->createCheckoutUrl('Order #123', '10.00');
// Title becomes 'Order 123' automatically

// Invalid merchant order ID - automatically sanitized
$checkoutUrl = $client->createCheckoutUrl('Test', '10.00', 'ORDER_123');
// merchantOrderId becomes 'ORDER123' automatically
```

### Manual Validation

```php
use Melaku\Telebirr\ParameterValidator;

// Validate before API call
$title = ParameterValidator::validateTitle('Order #123', false); // Throws exception
$amount = ParameterValidator::validateAmount('10.00');
$orderId = ParameterValidator::validateMerchantOrderId('ORDER_123', false); // Throws exception
```

### Configuration Validation

```php
use Melaku\Telebirr\Config;

$config = Config::forTest([...]);

// Validate configuration
try {
    $config->validate();
    echo "Configuration is valid!";
} catch (ConfigurationException $e) {
    echo "Configuration errors:\n";
    foreach ($config->getMissingFields() as $field) {
        echo "- Missing: {$field}\n";
    }
}
```

### Logging

```php
use Melaku\Telebirr\Telebirr;
use Melaku\Telebirr\Logger\LoggerInterface;

// Create a simple logger
class MyLogger implements LoggerInterface {
    public function log($level, $message, $context = []) {
        error_log("[{$level}] {$message}: " . json_encode($context));
    }
    public function debug($message, $context = []) { $this->log('debug', $message, $context); }
    public function info($message, $context = []) { $this->log('info', $message, $context); }
    public function warning($message, $context = []) { $this->log('warning', $message, $context); }
    public function error($message, $context = []) { $this->log('error', $message, $context); }
}

$client = new Telebirr($config, new MyLogger());
// All API calls will now be logged
```

### Helper Methods

```php
$client = new Telebirr($config);

// Generate valid merchant order ID
$orderId = $client->generateMerchantOrderId();

// Sanitize title
$cleanTitle = $client->sanitizeTitle('Order #123!'); // Returns 'Order 123'

// Format amount
$formatted = $client->formatAmount(10); // Returns '10.00'
$formatted = $client->formatAmount('10.5'); // Returns '10.50'

// Validate merchant order ID
if ($client->isValidMerchantOrderId('ORDER123')) {
    // Valid format
}
```

## Error Message Improvements

### Before:
```
Create order API returned HTTP 400: {"errorCode":"49401024995","errorMsg":"..."}
```

### After:
```
Create order API returned HTTP 400
Error Code: 49401024995
Error Message: Parameter:.merch_order_id type mismatch. [Required string pattern ^[A-Za-z0-9]+$]
Solution: Incoming parameters are missing mandatory parameters.Check whether all required parameters in the interface have been assigned.

This error indicates a parameter validation issue.
Common causes:
- Invalid merchant order ID format (must be alphanumeric only)
- Invalid title characters (special characters not allowed)
- Parameter type mismatch

Tip: Use ParameterValidator::validateTitle() and ParameterValidator::validateMerchantOrderId() to validate parameters before calling the API.
```

## Backward Compatibility

All improvements maintain 100% backward compatibility:

- ✅ All existing code continues to work without changes
- ✅ Validation is automatic (opt-out not needed)
- ✅ Sanitization happens automatically
- ✅ Logging is optional (NullLogger by default)
- ✅ Type hints are PHP 7.4+ compatible (no union types in signatures)

## Testing

The improvements have been tested with:
- PHP 7.4+
- Real Telebirr API integration
- Parameter validation edge cases
- Error message formatting
- Logging functionality

## Migration Guide

No migration needed! The library is fully backward compatible. However, you can take advantage of new features:

1. **Use helper methods** for cleaner code
2. **Add logging** for debugging
3. **Validate config** on startup
4. **Catch InvalidParameterException** for better error handling

## Next Steps

Consider:
- Adding unit tests for ParameterValidator
- Adding integration tests for error message improvements
- Documenting logging best practices
- Creating examples for common use cases
