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

// Create checkout URL (one line!)
$checkoutUrl = $client->createCheckoutUrl('Order #123', '100.00');

// Redirect customer to Telebirr
header('Location: ' . $checkoutUrl);
exit;
```

That's it! The library handles token management, order creation, and checkout URL generation automatically.

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
    $paymentData = ReturnUrlHandler::handle($_GET, $config);
    
    if ($paymentData['isSuccess']) {
        // Payment successful
        $orderId = $paymentData['merchantOrderId'];
        $amount = $paymentData['amount'];
        // Update your database, fulfill order, etc.
    }
} catch (\RuntimeException $e) {
    // Signature verification failed
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
    NotificationHandler::respondError('Invalid signature');
    exit;
}

// Process payment
if (NotificationHandler::isPaymentSuccessful($notification)) {
    $paymentInfo = NotificationHandler::extractPaymentInfo($notification);
    // Update database, fulfill order, etc.
    
    NotificationHandler::respondSuccess('Payment processed');
}
```

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
- `ext-openssl` extension
- `openssl` CLI available in PATH (for RSA-PSS signing)

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
