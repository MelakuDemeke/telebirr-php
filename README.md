<a href="https://aimeos.org/">
    <img src="img/telebirrlogo.png" alt="Telebirr" title="Aimeos" align="right" height="60" />
</a>

# Telebirr PHP Library (Web Checkout)
![](img/telebanner.png)

![GitHub branch checks state](https://img.shields.io/github/checks-status/MelakuDemeke/telebirr-php/main)
![GitHub repo size](https://img.shields.io/github/repo-size/MelakuDemeke/telebirr-php)
![GitHub issues](https://img.shields.io/github/issues/MelakuDemeke/telebirr-php)
![Packagist Downloads](https://img.shields.io/packagist/dt/melaku/telebirr?color=green&logo=packagist&logoColor=white)
![Packagist Stars](https://img.shields.io/packagist/stars/melaku/telebirr?logo=packagist&logoColor=white)
![GitHub](https://img.shields.io/github/license/MelakuDemeke/telebirr-php?style=flat)
![GitHub Repo stars](https://img.shields.io/github/stars/MelakuDemeke/telebirr-php?logo=github&style=flat)
![GitHub forks](https://img.shields.io/github/forks/MelakuDemeke/telebirr-php?logo=github&style=falt)
![GitHub commit activity](https://img.shields.io/github/commit-activity/m/MelakuDemeke/telebirr-php?logo=github)
![GitHub last commit](https://img.shields.io/github/last-commit/MelakuDemeke/telebirr-php)

A modern PHP library for integrating **Telebirr Web Checkout (C2B)** payments. Telebirr is a mobile money service developed by Huawei and owned by Ethio telecom.

This library provides a simple, easy-to-use API for handling Telebirr payments, fully compliant with the [Telebirr H5 C2B Web Payment Integration Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder).

## 🚀 Quick Start

### Installation

```bash
composer require melaku/telebirr
```

### Basic Usage

```php
require 'vendor/autoload.php';

use Melaku\Telebirr\Config;
use Melaku\Telebirr\Telebirr;

// Configure (test environment)
$config = Config::forTest([
    'fabricAppId'   => 'YOUR_FABRIC_APP_ID',
    'appSecret'     => 'YOUR_APP_SECRET',
    'merchantAppId' => 'YOUR_MERCHANT_APP_ID',
    'merchantCode'  => 'YOUR_MERCHANT_CODE',
    'privateKey'    => 'YOUR_PRIVATE_KEY_PEM',
    'notifyUrl'     => 'https://your-domain.com/telebirr/notify',
    'redirectUrl'   => 'https://your-domain.com/telebirr/return',
]);

$client = new Telebirr($config);

// Create checkout URL (one line!). Returns a CheckoutResult.
$result = $client->createCheckoutUrl('Order 123', '100.00');

// IMPORTANT: persist the EXACT merch_order_id the library used — Telebirr
// echoes this value back in notifications and on the return URL. Storing a
// different value (e.g. one you thought you passed) can cause lookup misses.
saveOrder($result->getMerchOrderId(), $result->getPrepayId()); // your code

// Redirect customer to Telebirr
header('Location: ' . $result->getCheckoutUrl());
exit;
```

That's it! The library handles token management, order creation, and checkout URL generation automatically.

> **Merchant order id charset:** a merch_order_id must match `^[A-Za-z0-9]+$`
> (ASCII letters and digits only — no `-`, `_`, `.` or spaces). Invalid ids now
> throw an `InvalidParameterException` instead of being silently rewritten. Pass
> `null` to have a valid id generated for you, and read it back from the result.

### In-App SDK Payment

If your mobile app's Telebirr SDK initiates the payment instead of a browser
redirect, use `createInAppOrder()`. There's no checkout URL for this flow — the
response's `receiveCode` must be passed to the mobile SDK to continue the payment.

```php
$tokenInfo   = $client->applyFabricToken();
$fabricToken = $tokenInfo['token'];

$order = $client->createInAppOrder($fabricToken, 'Order 123', '100.00');
$receiveCode = $order['biz_content']['receiveCode'];

// Send the receiveCode to your mobile app for the SDK to complete the payment.
header('Content-Type: application/json');
echo json_encode(['receiveCode' => $receiveCode]);
```

## 📋 Configuration

### Required Credentials

You'll receive these from Telebirr:
- `fabricAppId` - Your Fabric App ID (UUID)
- `appSecret` - Your App Secret
- `merchantAppId` - Your Merchant App ID
- `merchantCode` - Your Merchant Code (6-digit)
- `privateKey` - Your RSA Private Key (PEM format)
- `notifyUrl` - Server-to-server notification URL (required)
- `redirectUrl` - User return URL after payment (optional)

### Environment Setup

The library automatically uses the correct URLs based on environment:

```php
// Test/Development
$config = Config::forTest([...]);

// Production
$config = Config::forProduction([...]);

// Auto-detect from environment variable
$config = Config::fromEnvironment([...]);
// Set: export TELEBIRR_ENVIRONMENT=production
```

Default endpoints used by the library:

- Test API: https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway
- Production API: https://superapp.ethiomobilemoney.et:38443/apiaccess/payment/gateway
- Test Web Checkout Redirect: https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?
- Production Web Checkout Redirect: https://superapp.ethiomobilemoney.et:38443/payment/web/paygate?

## 💡 Key Features

- ✅ **Simple API** - One-line checkout URL generation
- ✅ **Automatic Token Management** - No need to handle tokens manually
- ✅ **Signature Verification** - Built-in helpers for return URLs and notifications
- ✅ **Helper Classes** - `ReturnUrlHandler`, `NotificationHandler`, `PaymentStatus`
- ✅ **Environment Support** - Automatic test/production URL handling
- ✅ **Full Compliance** - Follows Telebirr H5 C2B Web Payment Integration spec

## 📖 Common Use Cases

### Handle Payment Return

```php
use Melaku\Telebirr\ReturnUrlHandler;

try {
    // Fails closed: throws if the signature is missing or invalid.
    $paymentData = ReturnUrlHandler::handle($_GET, $config);

    if ($paymentData['isSuccess']) {
        $orderId = $paymentData['merchantOrderId'];

        // The return URL comes through the user's browser and is spoofable even
        // when signed. For anything that fulfils an order, confirm the real
        // status server-to-server before acting on it:
        $tokenInfo = $client->applyFabricToken();
        $status = $client->queryOrder($tokenInfo['token'], null, $orderId);
        $confirmed = ($status['biz_content']['trade_status'] ?? '') ;

        // Update your database / fulfill order only after this confirmation.
    }
} catch (\RuntimeException $e) {
    // Missing/invalid signature
    http_response_code(400);
    echo "Invalid payment data";
}
```

### Handle Payment Notifications

```php
use Melaku\Telebirr\NotificationHandler;

$rawData = file_get_contents('php://input');
$notification = NotificationHandler::parse($rawData);

// Verify signature
if (!NotificationHandler::verify($notification, $config)) {
    // respond* now RETURN a NotificationResponse (no header()/echo). In a
    // framework, convert it to your Response object. In bare PHP, call send().
    NotificationHandler::respondError('Invalid signature')->send();
    exit;
}

// Process payment
if (NotificationHandler::isPaymentSuccessful($notification)) {
    $paymentInfo = NotificationHandler::extractPaymentInfo($notification);
    // Update database, fulfill order, etc.

    NotificationHandler::respondSuccess('Payment processed')->send();
}
```

> **Framework usage:** instead of `->send()`, build a native response, e.g. in
> Laravel: `return response(json: $resp->getBody(), status: $resp->getStatusCode());`

### Query Order Status

```php
$tokenInfo = $client->applyFabricToken();
$orderStatus = $client->queryOrder($tokenInfo['token'], null, 'YOUR_ORDER_ID');

$tradeStatus = $orderStatus['biz_content']['trade_status'] ?? '';
if (strtoupper($tradeStatus) === 'PAY_SUCCESS') {
    // Payment successful
}
```

### Process Refund

```php
$tokenInfo = $client->applyFabricToken();
$refundResult = $client->refundOrder(
    $tokenInfo['token'],
    '50.00',              // Refund amount
    'PAYMENT_ORDER_ID',   // or null
    'MERCHANT_ORDER_ID',  // or null
    'Refund reason'       // Optional
);
```

## 🔧 Requirements

- PHP >= 7.4
- `ext-curl` extension
- `ext-openssl` extension (used by the legacy `Notify` class for payload decryption only)
- **phpseclib/phpseclib** (^3.0) — **Signer** and **SignatureVerifier** use phpseclib only (pure-PHP). No OpenSSL CLI or ext-openssl required for signing/verification. Works on all platforms including Windows. Algorithm: RSA-PSS, SHA256, MGF1-SHA256, salt length 32.
- **psr/log** (^1.1 || ^2.0 || ^3.0) — the library type-hints the standard `Psr\Log\LoggerInterface`, so any PSR-3 logger (Monolog, Laravel's logger, …) drops straight in.

## ⚙️ Advanced Configuration

### TLS & timeouts

The default HTTP client verifies the gateway's TLS certificate and applies
timeouts (a payment gateway must not be called over an unverified or unbounded
connection). Override only if you must:

```php
$config = Config::forProduction([
    // ... credentials ...
    'verifySsl'      => true,   // default true — leave on in production
    'caBundlePath'   => null,   // optional path to a custom CA bundle (PEM)
    'timeout'        => 30,     // total request timeout (seconds)
    'connectTimeout' => 10,     // connection timeout (seconds)
]);
```

### PSR-3 logging

```php
use Monolog\Logger;

$log = new Logger('telebirr');
$client = new Telebirr($config, $log); // request/response logging (secrets & PII redacted)
```

### Injecting a custom HTTP client (testing)

The third constructor argument accepts any `Melaku\Telebirr\Http\HttpClientInterface`,
so you can unit-test without hitting the network:

```php
use Melaku\Telebirr\Http\HttpClientInterface;
use Melaku\Telebirr\Http\HttpResponse;

$fake = new class implements HttpClientInterface {
    public function post(string $url, array $headers, string $body): HttpResponse {
        return new HttpResponse(200, '{"token":"Bearer TEST"}');
    }
};

$client = new Telebirr($config, null, $fake);
```

### Catching errors

Every exception the library throws implements
`Melaku\Telebirr\Exceptions\TelebirrExceptionInterface`, so you can catch them
all in one place. API failures throw `ApiException`, which exposes
`getHttpStatus()`, `getErrorCode()` and `getResponseBody()`.

## 📚 Documentation

For detailed documentation, API reference, and advanced usage examples, visit our documentation site:

**🔗 [Full Documentation](https://telebirr-php-docs.vercel.app)** *(Coming Soon)*

The documentation includes:
- Complete API reference
- Step-by-step integration guides
- Advanced configuration options
- Signature verification details
- Webhook/notification handling
- Error handling and troubleshooting
- Security best practices

## 🛠️ Helper Classes

The library provides several helper classes to simplify common tasks:

- **`ReturnUrlHandler`** - Parse and verify return URL parameters
- **`NotificationHandler`** - Parse and verify payment notifications
- **`PaymentStatus`** - Check payment status values
- **`SignatureVerifier`** - Verify signatures from Telebirr

## 🔒 Security Notes

- Always verify signatures before processing payments
- Use HTTPS for all payment endpoints
- Store credentials in environment variables, not in code
- Implement idempotency checks for notifications
- Never trust return URL parameters alone - verify with server-to-server notifications

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📄 License

This project is licensed under the MIT License.

## 🔗 Links

- [Telebirr Developer Portal](https://developer.ethiotelecom.et/)
- [Telebirr H5 C2B Integration Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder)
- [Packagist](https://packagist.org/packages/melaku/telebirr)
- [GitHub Repository](https://github.com/MelakuDemeke/telebirr-php)

---

**Need help?** Check out the [full documentation](https://telebirr-php-docs.vercel.app) or open an issue on GitHub.
