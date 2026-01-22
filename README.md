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

Telebirr-Php is a PHP library for [telebirr](https://www.ethiotelecom.et/telebirr/).  
Telebirr is a mobile money service developed by Huawei that is owned and was launched by Ethio telecom.  
This library focuses on the **Web Checkout (C2B)** flow and provides a modern, easy-to-use API, mirroring the official demo implementation.

## Table of content

- [Telebirr PHP Library (Web Checkout)](#telebirr-php-library-web-checkout)
  - [Table of content](#table-of-content)
  - [Installation](#installation)
    - [Composer](#composer)
  - [Configuration](#configuration)
  - [Basic usage (recommended)](#basic-usage-recommended)
  - [Advanced usage (manual steps)](#advanced-usage-manual-steps)
  - [Webhook / Notification handling](#webhook--notification-handling)
  - [Security & Requirements](#security--requirements)

## Installation
### Composer
```bash
composer require melaku/telebirr
```

## Configuration

You will receive the required information from Telebirr similar to:

| merchant name | short code | APP ID | APP KEY | Private key | Web base URL | API base URL |
| ------------- | ---------- | ------ | ------- | ----------- | ------------ | ------------ |
| owner name    | 6-digit    | UUID   | secret  | RSA key     | web payment  | gateway API  |

Store these in environment variables or a config file, then create a `Melaku\Telebirr\Config`:

```php
use Melaku\Telebirr\Config;

$config = new Config([
    'baseUrl'       => 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway',
    'webBaseUrl'    => 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?',
    'fabricAppId'   => getenv('TELEBIRR_FABRIC_APP_ID'),
    'appSecret'     => getenv('TELEBIRR_APP_SECRET'),
    'merchantAppId' => getenv('TELEBIRR_MERCHANT_APP_ID'),
    'merchantCode'  => getenv('TELEBIRR_MERCHANT_CODE'),
    'privateKey'    => getenv('TELEBIRR_PRIVATE_KEY_PEM'),
    'notifyUrl'     => 'https://your-domain.com/telebirr/notify', // Required: server-to-server callback URL
    'redirectUrl'   => 'https://your-domain.com/telebirr/return', // Optional: user redirect after payment
]);
```

**Important:** `notifyUrl` is **required**. This is where Telebirr will send payment status updates (success/failure) via POST requests. This endpoint should:
- Be accessible from the internet (HTTPS recommended)
- Handle server-to-server notifications (not user-facing)
- Update your database and mark orders as paid/failed
- Return appropriate JSON responses

**Optional:** `redirectUrl` is where Telebirr redirects users after payment completion (success or failure). This is added to `biz_content` as `redirect_url` in the preOrder request. This is a user-facing page that displays payment results. If not provided, Telebirr may use a default redirect behavior.

## Basic usage (recommended)

```php
require 'vendor/autoload.php';

use Melaku\Telebirr\Config;
use Melaku\Telebirr\Telebirr;

$config = new Config([
    'baseUrl'       => 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway',
    'webBaseUrl'    => 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?',
    'fabricAppId'   => 'YOUR_FABRIC_APP_ID',
    'appSecret'     => 'YOUR_APP_SECRET',
    'merchantAppId' => 'YOUR_MERCHANT_APP_ID',
    'merchantCode'  => 'YOUR_MERCHANT_CODE',
    'privateKey'    => \"-----BEGIN PRIVATE KEY-----\\n...\\n-----END PRIVATE KEY-----\",
    'notifyUrl'     => 'https://your-domain.com/telebirr/notify',
]);

$client = new Telebirr($config);

// Title and amount come from your application / cart
$title  = 'My Order #123';
$amount = '100.00';

// High-level helper: does token + preOrder + checkout URL
$checkoutUrl = $client->createCheckoutUrl($title, $amount);

// Redirect customer to Telebirr checkout
header('Location: ' . $checkoutUrl);
exit;
```

## Advanced usage (manual steps)

```php
require 'vendor/autoload.php';

use Melaku\Telebirr\Config;
use Melaku\Telebirr\Telebirr;

$config = new Config([/* ... see above ... */]);
$client = new Telebirr($config);

// 1) Apply fabric token
$tokenInfo   = $client->applyFabricToken();
$fabricToken = $tokenInfo['token']; // "Bearer xxx"

// 2) Create order
$order = $client->createOrder($fabricToken, $title, $amount);

// 3) Build checkout URL
if (!isset($order['biz_content']['prepay_id'])) {
    throw new \RuntimeException('prepay_id missing: ' . json_encode($order));
}

$checkoutUrl = $client->buildCheckoutUrl($order['biz_content']['prepay_id']);
header('Location: ' . $checkoutUrl);
exit;
```

## Webhook / Notification handling

For server-to-server notifications (Telebirr calls your server), use a separate endpoint (e.g. `/telebirr/notify`):

```php
// notify.php
require 'vendor/autoload.php';

use Melaku\Telebirr\Config;

$config = new Config([/* same as before */]);

header('Content-Type: application/json');

$rawData = file_get_contents('php://input');
$notification = json_decode($rawData, true);

// TODO: verify signature if Telebirr provides it
// TODO: implement idempotency (do not process same payment twice)

// Example fields (check Telebirr docs):
// $prepayId      = $notification['prepay_id'] ?? null;
// $status        = $notification['status'] ?? null;
// $merchOrderId  = $notification['merch_order_id'] ?? null;

// Update your DB, mark order paid/failed, etc.

echo json_encode(['success' => true, 'message' => 'Notification processed']);
```

If you need to **decrypt legacy payments** from the older API using RSA public key, you can still use the existing `Melaku\Telebirr\Notify` class as before:

```php
use Melaku\Telebirr\Notify;

$publicKey = 'YOUR PUBLIC KEY (base64, without PEM headers)';
$data      = file_get_contents('php://input'); // raw encrypted data from Telebirr

$notify = new Notify($publicKey, $data);
$json   = $notify->getPaymentInfo(); // decrypted JSON string
```

## Complete Usage Guide

For detailed integration instructions, examples, and best practices, see **[USAGE_GUIDE.md](USAGE_GUIDE.md)**.

The guide includes:
- Step-by-step installation and configuration
- Complete payment flow examples
- Shopping cart integration
- Database integration patterns
- Webhook handling with idempotency
- Error handling strategies
- Security best practices
- Troubleshooting guide

## Security & Requirements

- **Requirements**
  - PHP >= 7.4
  - `ext-curl`
  - `ext-openssl`
  - `openssl` CLI available in `PATH` (used for RSA-PSS signing)

- **Best practices**
  - Never commit secrets (keys, app IDs) to version control
  - Keep all payment endpoints (`notify`, `return`) on HTTPS
  - Log all payment-related operations for audit and debugging
  - Implement idempotency in your notify handler (process each transaction once)
  - Validate and sanitize all external input before using it
  - Store credentials in environment variables, not in code