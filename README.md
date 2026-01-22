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
This library focuses on the **Web Checkout (C2B)** flow and provides a modern, easy-to-use API, fully compliant with the [Telebirr H5 C2B Web Payment Integration Quick Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder), covering all steps from order creation to payment completion.

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
// Optional: pass your own merchant order ID
// $checkoutUrl = $client->createCheckoutUrl($title, $amount, 'MY-ORDER-123');

// Redirect customer to Telebirr checkout
header('Location: ' . $checkoutUrl);
exit;
```

## Advanced usage (manual steps)

This approach gives you more control over each step of the payment flow:

```php
require 'vendor/autoload.php';

use Melaku\Telebirr\Config;
use Melaku\Telebirr\Telebirr;

$config = new Config([/* ... see above ... */]);
$client = new Telebirr($config);

// 1) Apply fabric token
$tokenInfo   = $client->applyFabricToken();
$fabricToken = $tokenInfo['token']; // "Bearer xxx"

// 2) Create order (requestCreateOrder API endpoint)
// This calls POST /payment/v1/merchant/preOrder per H5 C2B Web Payment Integration spec
// See: https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder
$order = $client->createOrder($fabricToken, $title, $amount);
// Optional: pass your own merchant order ID
// $order = $client->createOrder($fabricToken, $title, $amount, 'MY-ORDER-123');

// The createOrder() method validates:
// - HTTP status code (200-299)
// - API error responses (code/message)
// - Presence of prepay_id in biz_content

// 3) Generate checkout URL from prepay_id (Generate_Check_Url)
// This calls the Generate_Check_Url step per H5 C2B Web Payment Integration spec
// See: https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Generate_Check_Url
$checkoutUrl = $client->buildCheckoutUrl($order['biz_content']['prepay_id']);
header('Location: ' . $checkoutUrl);
exit;
```

### requestCreateOrder API Details

The `createOrder()` method implements the **requestCreateOrder** endpoint from the Telebirr H5 C2B Web Payment Integration Quick Guide:

- **Endpoint**: `POST {baseUrl}/payment/v1/merchant/preOrder`
- **Request includes**: All required fields (`notify_url`, `appid`, `merch_code`, `merch_order_id`, `trade_type`, `title`, `total_amount`, `trans_currency`, `timeout_express`) plus optional `redirect_url`
- **Response validation**: Automatically validates `biz_content.prepay_id` is present
- **Error handling**: Comprehensive validation of HTTP status codes and API error responses

### Generate_Check_Url API Details

The `buildCheckoutUrl()` method implements the **Generate_Check_Url** step from the Telebirr H5 C2B Web Payment Integration Quick Guide:

- **Purpose**: Generates the final checkout URL that users will be redirected to for payment
- **Input**: `prepay_id` obtained from `createOrder()` response (`biz_content.prepay_id`)
- **Process**:
  1. Builds a signed query string with parameters: `appid`, `merch_code`, `nonce_str`, `prepay_id`, `timestamp`
  2. Signs the parameters using RSA-PSS SHA256 (same signing method as requestCreateOrder)
  3. Appends `sign` and `sign_type=SHA256WithRSA` to the query string
  4. Adds `version=1.0&trade_type=Checkout` parameters
  5. Combines with `webBaseUrl` to form the complete payment gateway URL
- **URL Format**: `{webBaseUrl}?appid={appid}&merch_code={merch_code}&nonce_str={nonce_str}&prepay_id={prepay_id}&timestamp={timestamp}&sign={sign}&sign_type=SHA256WithRSA&version=1.0&trade_type=Checkout`
- **Documentation**: See [Generate_Check_Url Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Generate_Check_Url)

### CheckOut Process Details

The **CheckOut** step is where users complete their payment on the Telebirr payment page. This process is documented in the Telebirr H5 C2B Web Payment Integration Quick Guide:

**CheckOut Flow:**
1. **User Redirect**: After calling `buildCheckoutUrl()` or `createCheckoutUrl()`, redirect the user to the returned URL
2. **Payment Page**: User sees the Telebirr payment page with order details
3. **Payment Completion**: User completes payment (or cancels) on Telebirr
4. **Return Redirect**: Telebirr redirects user back to your `redirectUrl` (if configured) with payment status parameters
5. **Server Notification**: Telebirr also sends a server-to-server notification to your `notifyUrl`

**Return URL Parameters** (when user is redirected back):
- `trade_status`: Payment status (e.g., `PAY_SUCCESS`, `PAY_FAILED`, `PAY_CANCEL`)
- `payment_order_id`: Telebirr's payment order ID
- `merch_order_id`: Your merchant order ID (from `createOrder`)
- `total_amount`: Payment amount
- `trans_currency`: Currency (typically `ETB`)
- `trans_end_time`: Transaction completion timestamp
- `notify_time`: Notification timestamp
- `appid`: Merchant application ID
- `merch_code`: Merchant code
- `sign`: Signature for verification
- `sign_type`: Signature type (typically `SHA256WithRSA`)

**Important Notes:**
- The return URL (`redirectUrl`) is **user-facing** - display payment results to the user
- The notification URL (`notifyUrl`) is **server-to-server** - update your database and process business logic
- Always verify payment status on your server using the `notifyUrl` endpoint, not just the return URL
- The return URL may be accessed multiple times or by users who didn't complete payment
- Implement idempotency checks to avoid processing the same payment twice

**Example Return URL Handler:**
```php
// return.php - User-facing return page
$tradeStatus = $_GET['trade_status'] ?? '';
$paymentOrderId = $_GET['payment_order_id'] ?? '';
$merchOrderId = $_GET['merch_order_id'] ?? '';
$totalAmount = $_GET['total_amount'] ?? '';

// Check if payment was successful
$isSuccess = strtoupper($tradeStatus) === 'PAY_SUCCESS';

// Display result to user
if ($isSuccess) {
    echo "Payment successful! Order ID: {$merchOrderId}";
} else {
    echo "Payment failed or cancelled. Status: {$tradeStatus}";
}

// Note: Don't update database here - use notifyUrl for that
```

**Documentation**: See [CheckOut Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/%20CheckOut)

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