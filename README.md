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
    - [Environment-Based Configuration (Recommended)](#environment-based-configuration-recommended)
    - [Environment URLs](#environment-urls)
    - [Checking Current Environment](#checking-current-environment)
  - [Basic usage (recommended)](#basic-usage-recommended)
  - [Advanced usage (manual steps)](#advanced-usage-manual-steps)
    - [requestCreateOrder API Details](#requestcreateorder-api-details)
    - [Generate\_Check\_Url API Details](#generate_check_url-api-details)
    - [CheckOut Process Details](#checkout-process-details)
    - [queryOrder API Details](#queryorder-api-details)
    - [RefundOrder API Details](#refundorder-api-details)
  - [Webhook / Notification handling (Notify\_Callback)](#webhook--notification-handling-notify_callback)
    - [Quick Answer: What Does the Notify Class Do?](#quick-answer-what-does-the-notify-class-do)
    - [How Server-to-Server Notifications Work](#how-server-to-server-notifications-work)
    - [Two Types of Notifications](#two-types-of-notifications)
      - [1. H5 C2B Web Payment (Current/New) - JSON Format ✅](#1-h5-c2b-web-payment-currentnew---json-format-)
      - [2. Legacy API - Encrypted Format (Old) ⚠️](#2-legacy-api---encrypted-format-old-️)
    - [Notify\_Callback Overview](#notify_callback-overview)
    - [Notification Endpoint Implementation](#notification-endpoint-implementation)
    - [Notification Parameters](#notification-parameters)
    - [Best Practices](#best-practices)
    - [When Do You Need the `Notify` Class?](#when-do-you-need-the-notify-class)
  - [Complete Usage Guide](#complete-usage-guide)
  - [Request Signature Process](#request-signature-process)
    - [Signature Generation Steps](#signature-generation-steps)
    - [Excluded Fields](#excluded-fields)
    - [Important Notes](#important-notes)
    - [Example Signature Process](#example-signature-process)
  - [Helper Classes](#helper-classes)
    - [PaymentStatus](#paymentstatus)
    - [ReturnUrlHandler](#returnurlhandler)
    - [NotificationHandler](#notificationhandler)
  - [Security \& Requirements](#security--requirements)

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

### Environment-Based Configuration (Recommended)

The library supports automatic environment detection for test and production URLs:

**Option 1: Using environment helper methods**

```php
use Melaku\Telebirr\Config;

// For test/development environment
$config = Config::forTest([
    'fabricAppId'   => getenv('TELEBIRR_FABRIC_APP_ID'),
    'appSecret'     => getenv('TELEBIRR_APP_SECRET'),
    'merchantAppId' => getenv('TELEBIRR_MERCHANT_APP_ID'),
    'merchantCode'  => getenv('TELEBIRR_MERCHANT_CODE'),
    'privateKey'    => getenv('TELEBIRR_PRIVATE_KEY_PEM'),
    'notifyUrl'     => 'https://your-domain.com/telebirr/notify',
    'redirectUrl'   => 'https://your-domain.com/telebirr/return',
]);

// For production environment
$config = Config::forProduction([
    'fabricAppId'   => getenv('TELEBIRR_FABRIC_APP_ID'),
    'appSecret'     => getenv('TELEBIRR_APP_SECRET'),
    'merchantAppId' => getenv('TELEBIRR_MERCHANT_APP_ID'),
    'merchantCode'  => getenv('TELEBIRR_MERCHANT_CODE'),
    'privateKey'    => getenv('TELEBIRR_PRIVATE_KEY_PEM'),
    'notifyUrl'     => 'https://your-domain.com/telebirr/notify',
    'redirectUrl'   => 'https://your-domain.com/telebirr/return',
]);
```

**Option 2: Automatic environment detection**

```php
use Melaku\Telebirr\Config;

// Automatically detects from TELEBIRR_ENVIRONMENT or APP_ENV environment variable
// Defaults to 'test' if not set
$config = Config::fromEnvironment([
    'fabricAppId'   => getenv('TELEBIRR_FABRIC_APP_ID'),
    'appSecret'     => getenv('TELEBIRR_APP_SECRET'),
    'merchantAppId' => getenv('TELEBIRR_MERCHANT_APP_ID'),
    'merchantCode'  => getenv('TELEBIRR_MERCHANT_CODE'),
    'privateKey'    => getenv('TELEBIRR_PRIVATE_KEY_PEM'),
    'notifyUrl'     => 'https://your-domain.com/telebirr/notify',
    'redirectUrl'   => 'https://your-domain.com/telebirr/return',
]);

// Set environment variable:
// export TELEBIRR_ENVIRONMENT=production  # or 'test'
// Or in .env file:
// TELEBIRR_ENVIRONMENT=production
```

**Option 3: Explicit environment parameter**

```php
use Melaku\Telebirr\Config;

$config = new Config([
    'environment'   => 'production', // or 'test'
    'fabricAppId'   => getenv('TELEBIRR_FABRIC_APP_ID'),
    'appSecret'     => getenv('TELEBIRR_APP_SECRET'),
    'merchantAppId' => getenv('TELEBIRR_MERCHANT_APP_ID'),
    'merchantCode'  => getenv('TELEBIRR_MERCHANT_CODE'),
    'privateKey'    => getenv('TELEBIRR_PRIVATE_KEY_PEM'),
    'notifyUrl'     => 'https://your-domain.com/telebirr/notify',
    'redirectUrl'   => 'https://your-domain.com/telebirr/return',
]);
```

**Option 4: Manual URL configuration (backward compatible)**

If you need to specify URLs manually or use custom URLs:

```php
use Melaku\Telebirr\Config;

$config = new Config([
    'baseUrl'       => 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway', // Test
    // 'baseUrl'     => 'https://telebirrappcube.ethiomobilemoney.et:38443/apiaccess/payment/gateway', // Production
    'webBaseUrl'    => 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?', // Test
    // 'webBaseUrl'  => 'https://telebirrappcube.ethiomobilemoney.et:38443/payment/web/paygate?', // Production
    'fabricAppId'   => getenv('TELEBIRR_FABRIC_APP_ID'),
    'appSecret'     => getenv('TELEBIRR_APP_SECRET'),
    'merchantAppId' => getenv('TELEBIRR_MERCHANT_APP_ID'),
    'merchantCode'  => getenv('TELEBIRR_MERCHANT_CODE'),
    'privateKey'    => getenv('TELEBIRR_PRIVATE_KEY_PEM'),
    'notifyUrl'     => 'https://your-domain.com/telebirr/notify',
    'redirectUrl'   => 'https://your-domain.com/telebirr/return',
]);
```

### Environment URLs

The library automatically uses the correct URLs based on environment:

- **Test/Development**: 
  - API: `https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway`
  - Web: `https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?`

- **Production**: 
  - API: `https://telebirrappcube.ethiomobilemoney.et:38443/apiaccess/payment/gateway`
  - Web: `https://telebirrappcube.ethiomobilemoney.et:38443/payment/web/paygate?`

### Checking Current Environment

```php
// Check if using test environment
if ($config->isTest()) {
    echo "Using test environment";
}

// Check if using production environment
if ($config->isProduction()) {
    echo "Using production environment";
}

// Get current environment
$env = $config->getEnvironment(); // Returns 'test' or 'production'
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

### queryOrder API Details

The `queryOrder()` method implements the **queryOrder** endpoint from the Telebirr H5 C2B Web Payment Integration Quick Guide:

- **Purpose**: Query the payment status of an order to verify payment completion or check order details
- **Endpoint**: `POST {baseUrl}/payment/v1/merchant/queryOrder`
- **Use Cases**:
  - Verify payment status when user returns from Telebirr (before trusting return URL parameters)
  - Check order status periodically (polling)
  - Reconcile payments and handle edge cases
  - Verify payment before fulfilling orders
- **Query Options**: You can query by either:
  - `prepay_id`: The payment order ID from `createOrder()` response
  - `merch_order_id`: Your merchant order ID
  - At least one must be provided
- **Response**: Returns order details including:
  - `trade_status`: Payment status (e.g., `PAY_SUCCESS`, `PAY_FAILED`, `PAY_CANCEL`)
  - `payment_order_id`: Telebirr's payment order ID
  - `merch_order_id`: Your merchant order ID
  - `total_amount`: Payment amount
  - `trans_currency`: Currency
  - Other transaction details

**Example Usage:**
```php
// After user returns from Telebirr, verify payment status
$tokenInfo = $client->applyFabricToken();
$fabricToken = $tokenInfo['token'];

// Query by merchant order ID
$orderStatus = $client->queryOrder($fabricToken, null, 'MY-ORDER-123');

// Or query by prepay_id
$orderStatus = $client->queryOrder($fabricToken, 'prepay_id_here', null);

// Check payment status
$tradeStatus = $orderStatus['biz_content']['trade_status'] ?? '';
if (strtoupper($tradeStatus) === 'PAY_SUCCESS') {
    // Payment successful - update database, fulfill order, etc.
} else {
    // Payment failed or pending
}
```

**Important Notes:**
- Always verify payment status using `queryOrder()` before trusting return URL parameters
- The return URL can be accessed multiple times or by users who didn't complete payment
- Use `queryOrder()` to get authoritative payment status from Telebirr
- Implement proper error handling for API failures
- Consider caching query results to avoid excessive API calls

**Documentation**: See [queryOrder Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/queryOrder)

### RefundOrder API Details

The `refundOrder()` method implements the **RefundOrder** endpoint from the Telebirr H5 C2B Web Payment Integration Quick Guide:

- **Purpose**: Initiate a refund for a completed payment transaction
- **Endpoint**: `POST {baseUrl}/payment/v1/merchant/refund`
- **Use Cases**:
  - Process customer refunds for returned items
  - Handle order cancellations after payment
  - Partial refunds (refund less than the original amount)
  - Full refunds (refund the entire payment amount)
- **Refund Options**: You can refund by either:
  - `payment_order_id`: The payment order ID from Telebirr (from payment notification or queryOrder)
  - `merch_order_id`: Your merchant order ID
  - At least one must be provided
- **Refund Amount**: Can be partial (less than original) or full (equal to original payment)
- **Response**: Returns refund details including:
  - `refund_order_id`: Your refund order ID
  - `refund_status`: Refund processing status
  - `refund_amount`: Amount being refunded
  - `payment_order_id`: Original payment order ID
  - Other refund transaction details

**Example Usage:**
```php
// After verifying payment was successful, process a refund
$tokenInfo = $client->applyFabricToken();
$fabricToken = $tokenInfo['token'];

// Full refund by merchant order ID
$refundResult = $client->refundOrder(
    $fabricToken,
    '100.00',              // Refund amount (full refund)
    null,                  // payment_order_id (not provided)
    'MY-ORDER-123',        // merch_order_id
    'Customer requested refund', // Optional: refund reason
    'REFUND-001'           // Optional: your refund order ID
);

// Partial refund by payment order ID
$refundResult = $client->refundOrder(
    $fabricToken,
    '50.00',               // Partial refund amount
    'PAYMENT-ORDER-123',   // payment_order_id
    null,                  // merch_order_id (not provided)
    'Partial refund for returned item'
);

// Check refund status
$refundStatus = $refundResult['biz_content']['refund_status'] ?? '';
if (strtoupper($refundStatus) === 'SUCCESS' || strtoupper($refundStatus) === 'PROCESSING') {
    // Refund initiated successfully
    $refundOrderId = $refundResult['biz_content']['refund_order_id'] ?? '';
    // Update your database, notify customer, etc.
} else {
    // Refund failed
    // Handle error
}
```

**Important Notes:**
- Refunds can only be processed for successfully completed payments
- You can refund the full amount or a partial amount (less than original)
- Refund processing may take time - check status using queryOrder if needed
- Always verify the original payment was successful before processing refund
- Store refund_order_id for tracking and reconciliation
- Implement proper error handling for refund failures
- Consider business logic (e.g., refund policy, time limits)

**Troubleshooting:**
- **404 Error**: If you get a 404 error ("can not find api"), verify that:
  - The RefundOrder API is enabled for your account
  - You're using the correct base URL (development vs. production)
  - Your account has refund permissions
- **Error Code 60320025** ("The merchant failed to call the payment platform to refund"):
  - This error typically means the refund request was properly formatted but rejected by the platform
  - **Common causes:**
    - You're using a development/sandbox environment where refunds may not be enabled
    - Your account doesn't have refund permissions enabled
    - The original payment is not eligible for refund (not completed, too old, already refunded, etc.)
    - You need to use the production environment for refunds
  - **Solutions:**
    - Verify you're using the correct base URL (production vs development)
    - Ensure the original payment was successfully completed
    - Contact Telebirr support to enable refund capabilities for your account
    - Try using the production environment if you're currently in development
- **Base URLs**:
  - Development: `https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway`
  - Production: `https://telebirrappcube.ethiomobilemoney.et:38443/apiaccess/payment/gateway`

**Documentation**: See [RefundOrder Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/RefundOrder)

## Webhook / Notification handling (Notify_Callback)

The **Notify_Callback** is a critical component of the Telebirr H5 C2B Web Payment Integration. This is where Telebirr sends server-to-server payment status notifications.

### Quick Answer: What Does the Notify Class Do?

**For H5 C2B (this library):**
- ❌ **You DON'T use the `Notify` class**
- ✅ Telebirr sends **plain JSON** - just use `json_decode()`
- ✅ **No decryption needed**

**For Legacy API (old Telebirr):**
- ✅ **You DO use the `Notify` class**
- ✅ Telebirr sends **encrypted data** - decrypt it first
- ✅ Then parse the decrypted JSON

**Bottom line**: If you're using this library, Telebirr sends JSON directly. Just parse it. The `Notify` class is only for old legacy integrations.

### How Server-to-Server Notifications Work

**Important**: This is **NOT** something the user does. This is **automatic communication between Telebirr's server and your server**.

Here's how it works:

```
1. User completes payment on Telebirr payment page
   ↓
2. Telebirr's server automatically sends a POST request to YOUR notifyUrl
   (e.g., https://your-domain.com/telebirr/notify)
   ↓
3. YOUR server receives the notification
   ↓
4. YOUR server processes it (update database, send email, etc.)
   ↓
5. YOUR server returns a JSON response to Telebirr
```

**Key Points:**
- **No user involvement**: The user doesn't send anything. Telebirr's server calls your server automatically.
- **Automatic**: Happens in the background after payment completion.
- **Server-to-server**: Direct communication between Telebirr's server and your server.
- **Your endpoint**: You create a PHP file (e.g., `notify.php`) that receives and processes the notification.

### Two Types of Notifications

| Feature                  | H5 C2B (Current/New) ✅                                | Legacy API (Old)                            |
| ------------------------ | ----------------------------------------------------- | ------------------------------------------- |
| **Format**               | Plain JSON                                            | RSA-encrypted (base64)                      |
| **Decryption needed?**   | ❌ No                                                  | ✅ Yes                                       |
| **Notify class needed?** | ❌ No                                                  | ✅ Yes                                       |
| **Code example**         | `json_decode(file_get_contents('php://input'), true)` | `new Notify($key, $data)->getPaymentInfo()` |
| **This library uses**    | ✅ H5 C2B                                              | ❌ Legacy                                    |

#### 1. H5 C2B Web Payment (Current/New) - JSON Format ✅

**For the H5 C2B Web Payment Integration (which this library uses):**
- Telebirr sends **plain JSON data** (not encrypted)
- You just parse the JSON - **NO decryption needed**
- The `Notify` class is **NOT needed** for H5 C2B

**What happens:**
```
Telebirr Server → POST request → Your notify.php
   (sends JSON)                    (receives JSON)
```

**Example of what Telebirr sends:**
```json
{
  "trade_status": "PAY_SUCCESS",
  "payment_order_id": "123456789",
  "merch_order_id": "MY-ORDER-123",
  "total_amount": "100.00",
  "trans_currency": "ETB",
  "trans_end_time": "2024-01-01 12:00:00",
  "notify_time": "2024-01-01 12:00:01",
  "appid": "your-app-id",
  "merch_code": "your-merchant-code",
  "sign": "signature-here",
  "sign_type": "SHA256WithRSA"
}
```

**Your code (simple - no decryption):**
```php
// notify.php - Your endpoint
$rawData = file_get_contents('php://input'); // Get what Telebirr sent
$notification = json_decode($rawData, true); // Parse JSON - that's it!
// ✅ Done! No decryption needed for H5 C2B!

// Now use the data
$status = $notification['trade_status'];
$orderId = $notification['merch_order_id'];
// ... process payment ...
```

#### 2. Legacy API - Encrypted Format (Old) ⚠️

**For older Telebirr API integrations (NOT what this library uses):**
- Telebirr sends **RSA-encrypted data** (base64-encoded)
- You need to **decrypt it** using the `Notify` class
- This is **only for legacy integrations**, not H5 C2B

**What happens:**
```
Telebirr Server → POST request → Your notify.php
   (sends encrypted)              (must decrypt first)
```

**Example of what Telebirr sends (legacy):**
```
(base64-encoded encrypted string like: "aBc123XyZ...")
```

**Your code (requires decryption):**
```php
// notify.php - Legacy API only
$encryptedData = file_get_contents('php://input'); // Encrypted data

// Step 1: Decrypt using Notify class
$notify = new Notify($publicKey, $encryptedData);
$json = $notify->getPaymentInfo(); // Decrypt to get JSON string

// Step 2: Parse the decrypted JSON
$notification = json_decode($json, true); // Now parse JSON

// Now use the data
$status = $notification['trade_status'];
// ... process payment ...
```

**⚠️ Important**: If you're using this library (`melaku/telebirr`), you're using H5 C2B, so you **don't need** the `Notify` class. Only use it if you're integrating with the old legacy Telebirr API.

### Notify_Callback Overview

Per Telebirr H5 C2B Web Payment Integration Quick Guide (Notify_Callback):
- **Purpose**: Receive asynchronous payment status updates from Telebirr
- **Type**: Server-to-server POST request (not user-facing)
- **When**: Telebirr sends notifications when payment status changes (success, failure, cancellation)
- **URL**: Configured in `notifyUrl` when creating orders
- **Format**: JSON payload in POST request body (for H5 C2B)
- **Response**: Your endpoint must return a JSON response acknowledging receipt

**Documentation**: See [Notify_Callback Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Notify_Callback)

### Notification Endpoint Implementation

Create a secure endpoint (e.g., `/telebirr/notify.php`) on your server. **Telebirr will automatically call this URL** when payment status changes.

**Important**: 
- This file runs on **YOUR server**
- Telebirr's server **automatically calls it** (you don't call it)
- The user **never sees or interacts with this file**
- It's **server-to-server communication**

**Recommended: Using NotificationHandler (Easier)**

```php
// notify.php - This file is on YOUR server
// Telebirr's server will POST to this URL automatically

require 'vendor/autoload.php';

use Melaku\Telebirr\Config;
use Melaku\Telebirr\NotificationHandler;

// Load config
$configArray = require 'config.php';
$config = new Config($configArray);

try {
    // Get raw POST data
    $rawData = file_get_contents('php://input');
    
    // Log notification for audit
    error_log('Telebirr Notification Received: ' . $rawData);
    
    // Parse notification using NotificationHandler
    $notification = NotificationHandler::parse($rawData);
    
    // Extract payment information
    $paymentInfo = NotificationHandler::extractPaymentInfo($notification);
    
    // Verify signature (CRITICAL for security)
    if (!NotificationHandler::verify($notification, $config)) {
        error_log('Notification signature verification failed');
        NotificationHandler::respondError('Invalid signature');
        exit;
    }
    
    // TODO: Implement idempotency check (CRITICAL)
    // Check if you've already processed this notification using payment_order_id or merch_order_id
    // This prevents processing the same payment multiple times
    // if (isNotificationProcessed($paymentInfo['paymentOrderId'])) {
    //     NotificationHandler::respondSuccess('Notification already processed');
    //     exit;
    // }
    
    // Process based on payment status
    if (NotificationHandler::isPaymentSuccessful($notification)) {
        // Payment successful
        // TODO: Update your database - mark order as paid
        // TODO: Fulfill the order (send email, update inventory, etc.)
        // TODO: Mark notification as processed (for idempotency)
        
        error_log(sprintf(
            'Payment successful - payment_order_id: %s, merch_order_id: %s, amount: %s',
            $paymentInfo['paymentOrderId'],
            $paymentInfo['merchantOrderId'],
            $paymentInfo['amount']
        ));
        
        NotificationHandler::respondSuccess('Payment notification processed successfully');
    } else {
        // Payment failed or cancelled
        error_log(sprintf(
            'Payment status: %s - payment_order_id: %s, merch_order_id: %s',
            $paymentInfo['tradeStatus'],
            $paymentInfo['paymentOrderId'],
            $paymentInfo['merchantOrderId']
        ));
        
        // TODO: Update your database - mark order as failed/cancelled
        // TODO: Notify customer if needed
        // TODO: Mark notification as processed
        
        NotificationHandler::respondSuccess('Payment status notification processed');
    }
    
} catch (\InvalidArgumentException $e) {
    // Invalid JSON format
    error_log('Error parsing Telebirr notification: ' . $e->getMessage());
    NotificationHandler::respondError('Invalid notification format: ' . $e->getMessage());
} catch (\Exception $e) {
    // Other errors
    error_log('Error processing Telebirr notification: ' . $e->getMessage());
    NotificationHandler::respondError('Error processing notification: ' . $e->getMessage());
}
```

**Alternative: Manual Implementation**

```php
// notify.php - Manual implementation (for reference)

require 'vendor/autoload.php';

header('Content-Type: application/json');

// Get what Telebirr sent (it's already JSON for H5 C2B)
$rawData = file_get_contents('php://input');
$notification = json_decode($rawData, true);

// Log notification for audit
error_log('Telebirr Notification Received: ' . $rawData);

// Prepare response
$response = ['success' => false, 'message' => ''];

try {
    if (empty($notification)) {
        throw new \RuntimeException('Invalid notification data');
    }

    // Extract notification fields
    $tradeStatus = $notification['trade_status'] ?? '';
    $paymentOrderId = $notification['payment_order_id'] ?? '';
    $merchOrderId = $notification['merch_order_id'] ?? '';
    $totalAmount = $notification['total_amount'] ?? '';

    // TODO: Verify signature using SignatureVerifier
    // TODO: Implement idempotency check

    // Process notification based on trade_status
    if (strtoupper($tradeStatus) === 'PAY_SUCCESS' || strtoupper($tradeStatus) === 'SUCCESS') {
        // Payment successful
        // TODO: Update database, fulfill order, etc.
        $response['success'] = true;
        $response['message'] = 'Payment notification processed successfully';
    } else {
        // Payment failed or cancelled
        $response['success'] = true;
        $response['message'] = 'Payment status notification processed';
    }
    
} catch (\Exception $e) {
    error_log('Error processing Telebirr notification: ' . $e->getMessage());
    $response['success'] = false;
    $response['message'] = 'Error processing notification: ' . $e->getMessage();
    http_response_code(500);
}

echo json_encode($response);
```

### Notification Parameters

Telebirr sends the following parameters in the notification (per Notify_Callback spec):

- `trade_status`: Payment status (`PAY_SUCCESS`, `PAY_FAILED`, `PAY_CANCEL`)
- `payment_order_id`: Telebirr's payment order ID
- `merch_order_id`: Your merchant order ID (from `createOrder`)
- `total_amount`: Payment amount
- `trans_currency`: Currency (typically `ETB`)
- `trans_end_time`: Transaction completion timestamp
- `notify_time`: Notification timestamp
- `appid`: Merchant application ID
- `merch_code`: Merchant code
- `sign`: Signature for verification (if provided)
- `sign_type`: Signature type (if provided)

### Best Practices

1. **Idempotency**: Always check if a notification has already been processed before processing it again
2. **Signature Verification**: Verify the signature if Telebirr provides it to ensure the notification is authentic
3. **Logging**: Log all notifications for audit and debugging purposes
4. **Error Handling**: Return appropriate HTTP status codes (200 for success, 500 for errors)
5. **Response Format**: Always return a JSON response, even on errors
6. **Database Updates**: Update your database based on notification status
7. **HTTPS**: Use HTTPS for your notification endpoint
8. **Timeout Handling**: Process notifications quickly and return response promptly

### When Do You Need the `Notify` Class?

**For H5 C2B Web Payment Integration (this library):**
- ❌ **You DON'T need the `Notify` class**
- ✅ Telebirr sends plain JSON - just parse it with `json_decode()`
- ✅ See the example above in "Notification Endpoint Implementation"

**For Legacy API (old Telebirr integration):**
- ✅ **You DO need the `Notify` class**
- ✅ Telebirr sends encrypted data - you must decrypt it first
- ✅ Use the `Notify` class to decrypt, then parse JSON

**Example for Legacy API:**
```php
// ONLY for legacy API - NOT for H5 C2B!
use Melaku\Telebirr\Notify;

// Telebirr sends encrypted data (base64-encoded)
$encryptedData = file_get_contents('php://input');

// Decrypt using your public key
$publicKey = 'YOUR PUBLIC KEY (base64, without PEM headers)';
$notify = new Notify($publicKey, $encryptedData);
$json = $notify->getPaymentInfo(); // Decrypt to get JSON string

// Now parse the decrypted JSON
$notification = json_decode($json, true);

// Process notification...
```

**Summary:**
- **H5 C2B (current)**: `$notification = json_decode(file_get_contents('php://input'), true);` ✅
- **Legacy (old)**: Use `Notify` class to decrypt first, then parse JSON ✅

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

## Request Signature Process

The library implements the **Request Signature Process** per the Telebirr H5 C2B Web Payment Integration Quick Guide:

**Documentation**: See [Request Signature Process Guide](https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Request_signature_Process)

### Signature Generation Steps

1. **Collect Fields**: Gather all fields from the request object (excluding excluded fields)
2. **Flatten biz_content**: All fields within `biz_content` are flattened into the main field list
3. **Sort Fields**: Sort all fields alphabetically (ASCII order)
4. **Build Canonical String**: Create string in format `key1=value1&key2=value2&...`
5. **Sign**: Sign the canonical string using RSA-PSS SHA256 (SHA256withRSAandMGF1)
6. **Encode**: Return base64-encoded signature

### Excluded Fields

The following fields are **NOT** included in the signature:
- `sign` - The signature field itself
- `sign_type` - Signature type field
- `header` - Header information
- `refund_info` - Refund information (legacy)
- `openType` - Open type field
- `raw_request` - Raw request field
- `biz_content` - The wrapper field (but its **contents** ARE included)

### Important Notes

- ✅ All fields **within** `biz_content` **MUST** participate in the signature
- ✅ Fields are sorted **alphabetically** before signing
- ✅ Uses **RSA-PSS padding** with **SHA256** hash and **MGF1**
- ✅ Salt length is **32 bytes** (equal to hash length for SHA256)
- ✅ Signature is **base64-encoded** before being added to the request

### Example Signature Process

```php
// Request object
$request = [
    'timestamp' => '1234567890',
    'nonce_str' => 'ABC123',
    'method' => 'payment.preorder',
    'version' => '1.0',
    'biz_content' => [
        'appid' => 'YOUR_APP_ID',
        'merch_code' => 'YOUR_MERCH_CODE',
        'title' => 'Test Order',
        'total_amount' => '100.00'
    ]
];

// Step 1-3: Collect and sort fields (excluding excluded fields)
// Fields: appid, merch_code, method, nonce_str, timestamp, title, total_amount, version

// Step 4: Build canonical string
// "appid=YOUR_APP_ID&merch_code=YOUR_MERCH_CODE&method=payment.preorder&nonce_str=ABC123&timestamp=1234567890&title=Test Order&total_amount=100.00&version=1.0"

// Step 5-6: Sign and encode
// Signature = base64(RSA-PSS-SHA256(canonical_string))
```

The `Signer` class handles all of this automatically - you don't need to manually calculate signatures.

## Signature Verification

The library includes a `SignatureVerifier` class to verify signatures from Telebirr's return URLs and notifications. This is **critical for security** - always verify signatures before processing payments.

### Why Verify Signatures?

Signature verification ensures:
- **Authenticity**: The data came from Telebirr (not a fake/imposter)
- **Integrity**: The data hasn't been tampered with or modified

### Configuration

Add Telebirr's public key to your config:

```php
use Melaku\Telebirr\Config;

$config = Config::forTest([
    'fabricAppId'   => 'your-fabric-app-id',
    'appSecret'     => 'your-app-secret',
    'merchantAppId'  => 'your-merchant-app-id',
    'merchantCode'  => 'your-merchant-code',
    'privateKey'    => 'your-private-key-pem',
    'notifyUrl'     => 'https://your-domain.com/notify',
    'redirectUrl'   => 'https://your-domain.com/return',
    
    // Add Telebirr's public key for signature verification
    'telebirrPublicKey' => <<<KEY
-----BEGIN PUBLIC KEY-----
YOUR_TELEBIRR_PUBLIC_KEY_HERE
-----END PUBLIC KEY-----
KEY,
]);
```

**Note**: If Telebirr signs with YOUR private key, the library will automatically extract your public key from your private key. You don't need to provide `telebirrPublicKey` in that case.

### Verifying Return URL Signatures

When Telebirr redirects users to your `redirectUrl`, verify the signature:

**Recommended: Using ReturnUrlHandler (Easier)**

```php
use Melaku\Telebirr\ReturnUrlHandler;
use Melaku\Telebirr\Config;

// Load your config
$config = require 'config.php';
$config = new Config($config);

try {
    // Handle return URL - automatically verifies signature and extracts data
    $paymentData = ReturnUrlHandler::handle($_GET, $config);
    
    if ($paymentData['isSuccess']) {
        // ✅ Payment successful - update your database
        $paymentOrderId = $paymentData['paymentOrderId'];
        $merchantOrderId = $paymentData['merchantOrderId'];
        $amount = $paymentData['amount'];
        
        // Update database, fulfill order, etc.
        echo "Payment verified and successful!";
    } else {
        // Payment failed or cancelled
        echo "Payment status: " . $paymentData['tradeStatus'];
    }
} catch (\RuntimeException $e) {
    // ❌ Signature verification failed - DO NOT TRUST THIS DATA
    http_response_code(400);
    echo "Invalid signature - payment data may be tampered";
}
```

**Alternative: Manual Verification**

```php
use Melaku\Telebirr\SignatureVerifier;
use Melaku\Telebirr\Config;

// Load your config
$config = require 'config.php';
$config = new Config($config);

// Get parameters from return URL
$params = $_GET;

// Verify signature
try {
    $isValid = SignatureVerifier::verify($params, $config);
    
    if ($isValid) {
        // ✅ Signature verified - process payment
        $paymentOrderId = $params['payment_order_id'] ?? '';
        $tradeStatus = $params['trade_status'] ?? '';
        $totalAmount = $params['total_amount'] ?? '';
        
        if ($tradeStatus === 'PAY_SUCCESS') {
            // Payment successful - update your database
            echo "Payment verified and successful!";
        }
    } else {
        // ❌ Signature verification failed - DO NOT TRUST THIS DATA
        http_response_code(400);
        echo "Invalid signature - payment data may be tampered";
    }
} catch (\Exception $e) {
    // Error during verification
    error_log('Signature verification error: ' . $e->getMessage());
    http_response_code(500);
    echo "Verification error";
}
```

### Verifying Notification Signatures

For server-to-server notifications, verify signatures before processing:

**Recommended: Using NotificationHandler (Easier)**

```php
use Melaku\Telebirr\NotificationHandler;
use Melaku\Telebirr\Config;

// Load your config
$config = require 'config.php';
$config = new Config($config);

try {
    // Parse notification from raw JSON
    $rawData = file_get_contents('php://input');
    $notification = NotificationHandler::parse($rawData);
    
    // Verify signature
    if (!NotificationHandler::verify($notification, $config)) {
        NotificationHandler::respondError('Invalid signature');
        exit;
    }
    
    // Extract payment information
    $paymentInfo = NotificationHandler::extractPaymentInfo($notification);
    
    // Process based on payment status
    if (NotificationHandler::isPaymentSuccessful($notification)) {
        // ✅ Payment successful - update database, fulfill order, etc.
        // TODO: Implement idempotency check
        // TODO: Update database
        // TODO: Fulfill order
        
        NotificationHandler::respondSuccess('Payment notification processed');
    } else {
        // Payment failed or cancelled
        // TODO: Update database
        
        NotificationHandler::respondSuccess('Status notification processed');
    }
} catch (\InvalidArgumentException $e) {
    NotificationHandler::respondError('Invalid notification format: ' . $e->getMessage());
} catch (\Exception $e) {
    NotificationHandler::respondError('Error processing notification: ' . $e->getMessage());
}
```

**Alternative: Manual Verification**

```php
use Melaku\Telebirr\SignatureVerifier;
use Melaku\Telebirr\Config;

// Load your config
$config = require 'config.php';
$config = new Config($config);

// Get notification data (may be JSON or URL parameters)
$notificationData = json_decode(file_get_contents('php://input'), true);

// If notification includes signature parameters, verify them
if (isset($notificationData['sign']) && isset($notificationData['sign_type'])) {
    $isValid = SignatureVerifier::verify($notificationData, $config);
    
    if (!$isValid) {
        // ❌ Signature verification failed
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// ✅ Signature verified - process notification
// ... your notification processing code ...
```

### Using Public Key Directly

You can also pass the public key directly instead of using Config:

```php
use Melaku\Telebirr\SignatureVerifier;

$publicKey = <<<KEY
-----BEGIN PUBLIC KEY-----
YOUR_TELEBIRR_PUBLIC_KEY_HERE
-----END PUBLIC KEY-----
KEY;

$params = $_GET;
$isValid = SignatureVerifier::verify($params, $publicKey);
```

### Extracting Your Public Key

If Telebirr signs with your private key, extract your public key:

```php
use Melaku\Telebirr\SignatureVerifier;

$privateKey = 'your-private-key-pem';
$publicKey = SignatureVerifier::extractPublicKeyFromPrivateKey($privateKey);

// Use this public key for verification
$isValid = SignatureVerifier::verify($params, $publicKey);
```

### Getting Canonical String (Debugging)

To see what string was signed (for debugging):

```php
use Melaku\Telebirr\SignatureVerifier;

$params = $_GET;
$canonicalString = SignatureVerifier::getCanonicalString($params);
echo "Canonical string: " . $canonicalString;
```

### Important Security Notes

⚠️ **ALWAYS verify signatures before processing payments!**

- Never trust payment data without signature verification
- If verification fails, reject the payment and log the incident
- Contact Telebirr support if verification consistently fails
- Use server-to-server notifications (`notifyUrl`) for reliable payment processing
- Return URLs (`redirectUrl`) are user-facing and less secure

## Helper Classes

The library provides helper classes to simplify common tasks like handling return URLs and notifications. These classes handle signature verification, parsing, and provide convenient methods for checking payment status.

### PaymentStatus

The `PaymentStatus` class provides utility methods for checking payment status values.

```php
use Melaku\Telebirr\PaymentStatus;

$tradeStatus = 'PAY_SUCCESS';

// Check if payment was successful
if (PaymentStatus::isSuccess($tradeStatus)) {
    // Payment successful
}

// Check if payment failed
if (PaymentStatus::isFailure($tradeStatus)) {
    // Payment failed
}

// Check if payment was cancelled
if (PaymentStatus::isCancelled($tradeStatus)) {
    // Payment cancelled
}
```

**Methods:**
- `isSuccess(string $tradeStatus): bool` - Check if status indicates success (`PAY_SUCCESS`, `SUCCESS`, `PAID`)
- `isFailure(string $tradeStatus): bool` - Check if status indicates failure (`PAY_FAILED`, `FAILED`)
- `isCancelled(string $tradeStatus): bool` - Check if status indicates cancellation (`PAY_CANCEL`, `CANCEL`, `CANCELLED`)

### ReturnUrlHandler

The `ReturnUrlHandler` class simplifies handling Telebirr return URL parameters. It automatically verifies signatures, parses parameters, and extracts payment information.

```php
use Melaku\Telebirr\ReturnUrlHandler;
use Melaku\Telebirr\Config;

$config = new Config($configArray);

try {
    // Handle return URL - automatically verifies signature and extracts data
    $paymentData = ReturnUrlHandler::handle($_GET, $config);
    
    // Access payment information
    $tradeStatus = $paymentData['tradeStatus'];
    $paymentOrderId = $paymentData['paymentOrderId'];
    $merchantOrderId = $paymentData['merchantOrderId'];
    $amount = $paymentData['amount'];
    $currency = $paymentData['currency'];
    $isSuccess = $paymentData['isSuccess'];
    $timestamp = $paymentData['timestamp'];
    $rawParams = $paymentData['raw']; // All original parameters
    
    if ($isSuccess) {
        // Payment successful - update database, fulfill order, etc.
    }
} catch (\RuntimeException $e) {
    // Signature verification failed
    // DO NOT process payment - data may be tampered
    error_log('Return URL signature verification failed: ' . $e->getMessage());
}
```

**Methods:**
- `handle(array $params, Config $config): array` - Parse and verify return URL parameters. Returns array with:
  - `tradeStatus`: Payment status (e.g., 'PAY_SUCCESS')
  - `paymentOrderId`: Telebirr's payment order ID
  - `merchantOrderId`: Your merchant order ID
  - `amount`: Payment amount
  - `currency`: Currency code (typically 'ETB')
  - `isSuccess`: Boolean indicating if payment was successful
  - `timestamp`: Transaction end time
  - `raw`: All original parameters
- `isPaymentSuccessful(array $params): bool` - Check if payment was successful based on parameters

**Throws:** `\RuntimeException` if signature verification fails

### NotificationHandler

The `NotificationHandler` class simplifies handling Telebirr server-to-server payment notifications. It parses JSON, verifies signatures, and provides response helpers.

```php
use Melaku\Telebirr\NotificationHandler;
use Melaku\Telebirr\Config;

$config = new Config($configArray);

try {
    // Parse notification from raw JSON
    $rawData = file_get_contents('php://input');
    $notification = NotificationHandler::parse($rawData);
    
    // Verify signature
    if (!NotificationHandler::verify($notification, $config)) {
        NotificationHandler::respondError('Invalid signature');
        exit;
    }
    
    // Extract payment information
    $paymentInfo = NotificationHandler::extractPaymentInfo($notification);
    
    // Process based on payment status
    if (NotificationHandler::isPaymentSuccessful($notification)) {
        // Payment successful
        // TODO: Update database, fulfill order, implement idempotency check
        
        NotificationHandler::respondSuccess('Payment notification processed');
    } else {
        // Payment failed or cancelled
        // TODO: Update database
        
        NotificationHandler::respondSuccess('Status notification processed');
    }
} catch (\InvalidArgumentException $e) {
    // Invalid JSON format
    NotificationHandler::respondError('Invalid notification format: ' . $e->getMessage());
} catch (\Exception $e) {
    // Other errors
    NotificationHandler::respondError('Error processing notification: ' . $e->getMessage());
}
```

**Methods:**
- `parse(string $rawJson): array` - Parse notification from raw JSON input. Throws `\InvalidArgumentException` if JSON is invalid.
- `verify(array $notification, Config $config): bool` - Verify notification signature. Returns `false` if signature is missing or invalid.
- `respondSuccess(?string $message = null): void` - Send success response to Telebirr (JSON format).
- `respondError(string $message, int $httpCode = 500): void` - Send error response to Telebirr (JSON format with HTTP status code).
- `isPaymentSuccessful(array $notification): bool` - Check if notification indicates successful payment.
- `extractPaymentInfo(array $notification): array` - Extract payment information from notification. Returns array with:
  - `tradeStatus`: Payment status
  - `paymentOrderId`: Telebirr's payment order ID
  - `merchantOrderId`: Your merchant order ID
  - `amount`: Payment amount
  - `currency`: Currency code
  - `timestamp`: Transaction end time
  - `notifyTime`: Notification timestamp

**Best Practices:**
- Always verify signatures before processing notifications
- Implement idempotency checks to avoid processing the same payment twice
- Use `respondSuccess()` or `respondError()` to send proper responses to Telebirr
- Log all notifications for audit and debugging purposes

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