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

### SuperApp Mini App (H5 inside the Telebirr SuperApp)

When your merchant H5 page runs inside the Telebirr SuperApp, the payment is
launched from the front-end through the SuperApp's JS bridge, not through a
browser redirect. The flow needs two pieces from this library:

**1. Auto-login — `exchangeAuthToken()`.** The bridge hands your front-end a user
`access_token`; exchange it for the Telebirr profile (`openid` / payment authtoken)
so the shopper is already signed in inside the SuperApp:

```php
$profile = $client->exchangeAuthToken($accessTokenFromBridge);
// $profile['openid'] — Telebirr profile id (auto-login succeeded)
```

**2. Launch the payment — `buildPayRequest()`.** Create the order as usual, then
build the signed raw request string the bridge expects:

```php
$tokenInfo   = $client->applyFabricToken();
$order       = $client->createOrder($tokenInfo['token'], 'Order 123', '100.00', 'ORDER123');
$prepayId    = $order['biz_content']['prepay_id'];

$rawRequest  = $client->buildPayRequest($prepayId);
```

Pass `$rawRequest` to the front-end, which starts the payment sheet:

```js
window.ma.js_fun_start_pay(rawRequest); // resolves via the bridge callback
```

That's the whole backend. Settlement is unchanged — Telebirr POSTs the
server-to-server notification to your `notifyUrl`; verify it with
`NotificationHandler` and confirm the real status with `getOrderStatus()`
before fulfilling.

## 📋 Configuration

### Required Credentials

You'll receive these from Telebirr:
- `fabricAppId` - Your Fabric App ID (UUID)
- `appSecret` - Your App Secret
- `merchantAppId` - Your Merchant App ID
- `merchantCode` - Your Merchant Code (6-digit)
- `privateKey` - Your RSA Private Key
- `notifyUrl` - Server-to-server notification URL (required)
- `redirectUrl` - User return URL after payment (optional)

### Key formats — bare base64 is fine

Ethio Telecom issues merchant keys as **bare base64 DER** (a single long
`MIIEvgIBADANBgk…` line, no `-----BEGIN…-----` armor). Pass it exactly as
issued — the library normalizes it to PEM automatically, picking the right
header (PKCS#8 vs PKCS#1) for you. Proper PEM works too, including PEM whose
newlines were flattened to literal `\n` by a `.env` file.

### Environment Setup

The library automatically uses the correct URLs based on environment:

```php
// Test/Development
$config = Config::forTest([...]);

// Production
$config = Config::forProduction([...]);

// Zero-config: read everything from environment variables
$config = Config::fromEnvironment();
```

`Config::fromEnvironment()` reads (any explicit option overrides its variable;
`$_ENV`, `$_SERVER`, and `getenv()` are all checked, so it works under
php-fpm/Laravel too):

| Variable | Maps to |
|---|---|
| `TELEBIRR_ENVIRONMENT` (then `APP_ENV`) | `environment` |
| `TELEBIRR_FABRIC_APP_ID` | `fabricAppId` |
| `TELEBIRR_APP_SECRET` | `appSecret` |
| `TELEBIRR_MERCHANT_APP_ID` | `merchantAppId` |
| `TELEBIRR_MERCHANT_CODE` | `merchantCode` |
| `TELEBIRR_PRIVATE_KEY` | `privateKey` (PEM or bare base64) |
| `TELEBIRR_NOTIFY_URL` | `notifyUrl` |
| `TELEBIRR_REDIRECT_URL` | `redirectUrl` |
| `TELEBIRR_PUBLIC_KEY` | `telebirrPublicKey` |

Default endpoints used by the library:

- Test API: https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway
- Production API: https://superapp.ethiomobilemoney.et:38443/apiaccess/payment/gateway
- Test Web Checkout Redirect: https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?
- Production Web Checkout Redirect: https://superapp.ethiomobilemoney.et:38443/payment/web/paygate?

## 💡 Key Features

- ✅ **Simple API** - One-call checkout (`createCheckoutUrl`) and one-call verification (`getOrderStatus`)
- ✅ **Automatic Token Management** - Fabric tokens are fetched, cached until expiry, and refreshed for you
- ✅ **Key normalization** - Bare base64 keys (as Ethio Telecom issues them) or PEM, both just work
- ✅ **TLS that just works** - Falls back to a bundled Telebirr CA chain when the test gateway's incomplete chain fails the system store; no `verifySsl => false` needed
- ✅ **Structured errors + opt-in retry** - Branch on `$e->getTelebirrCode()`; retry transient sandbox errors with backoff
- ✅ **Signature Verification** - Built-in helpers for return URLs and notifications
- ✅ **Helper Classes** - `ReturnUrlHandler`, `NotificationHandler`, `PaymentStatus`
- ✅ **Environment Support** - Automatic test/production URL handling
- ✅ **Full Compliance** - Follows Telebirr H5 C2B Web Payment Integration spec

## 📖 Common Use Cases

### Verify a payment (`getOrderStatus`)

The one-call, server-to-server way to confirm what actually happened to an
order — the verification counterpart to `createCheckoutUrl`. Token handling
and response mapping are done for you:

```php
$status = $client->getOrderStatus('YOUR_MERCH_ORDER_ID');

$status->paid;           // bool — true ONLY on an explicit success status (fails closed)
$status->tradeStatus;    // e.g. 'PAY_SUCCESS'
$status->amount;         // e.g. '100.00' — VERIFY this against your own order amount
$status->currency;       // 'ETB'
$status->paymentOrderId; // Telebirr's transaction reference (or null)
$status->raw;            // the full queryOrder response if you need more
```

### Handle Payment Return

```php
use Melaku\Telebirr\ReturnUrlHandler;

try {
    // Fails closed: throws if the signature is missing or invalid.
    $paymentData = ReturnUrlHandler::handle($_GET, $config);

    if ($paymentData['isSuccess']) {
        // The return URL comes through the user's browser and is spoofable even
        // when signed. For anything that fulfils an order, confirm the real
        // status server-to-server before acting on it:
        $status = $client->getOrderStatus($paymentData['merchantOrderId']);
        if ($status->paid && $status->amount === $expectedAmount) {
            // Update your database / fulfill the order — idempotently (see below).
        }
    }
} catch (\RuntimeException $e) {
    // Missing/invalid signature
    http_response_code(400);
    echo "Invalid payment data";
}
```

#### Return-URL parameters (the raw contract)

Telebirr redirects the user's browser to your `redirectUrl` with these query
parameters appended (snake_case):

| Parameter | Meaning |
|---|---|
| `merch_order_id` | Your merchant order id, echoed back verbatim |
| `payment_order_id` | Telebirr's transaction reference |
| `trade_status` | e.g. `PAY_SUCCESS`, `PAY_FAILED`, `PAY_CANCEL` |
| `total_amount` | Order amount |
| `trans_currency` | Currency (`ETB`) |
| `trans_end_time` | Transaction end time |
| `sign`, `sign_type` | RSA-PSS signature over the other params |

`ReturnUrlHandler::handle()` verifies the signature and maps these for you —
the table is here for when you're debugging the raw redirect.

### The idempotent settlement pattern (recommended)

The browser return and the server notification **race** — either can arrive
first, both can arrive, and neither should be trusted on its own. The
production-correct shape:

1. On checkout, store a row keyed by `merchOrderId` with `status='pending'`
   and the expected `amount`.
2. On **both** the return handler and the notify handler, call
   `$client->getOrderStatus($merchOrderId)` — never trust the callback params.
3. Verify `$status->paid === true` **and** `$status->amount` matches your
   stored amount.
4. Grant idempotently with a compare-and-set, so the racing paths can't
   double-fulfill:

```php
function settle(Telebirr $client, PDO $db, string $merchOrderId): void
{
    $status = $client->getOrderStatus($merchOrderId);
    if (!$status->paid) {
        return;
    }

    // Atomic claim: only one caller flips pending → success.
    $stmt = $db->prepare(
        "UPDATE orders SET status = 'success'
         WHERE merch_order_id = :id AND status = 'pending' AND amount = :amount"
    );
    $stmt->execute(['id' => $merchOrderId, 'amount' => $status->amount]);

    if ($stmt->rowCount() === 1) {
        fulfillOrder($merchOrderId); // runs exactly once
    }
}
```

#### Notification acknowledgement contract

- Telebirr POSTs the notification as a JSON body to your `notifyUrl`.
- Acknowledge success with **HTTP 200** and a JSON body — this is what
  `NotificationHandler::respondSuccess()` emits: `{"success": true}`.
- Any non-2xx status tells Telebirr the delivery failed; it will **retry the
  notification** later. Respond 200 once you have durably recorded the event,
  and reserve error responses for "I could not record this, please retry".
- Your `notifyUrl` must be publicly reachable — `localhost` or a private
  address will never receive anything (the library warns about this at
  construction time). In development use a tunnel (ngrok, cloudflared).

### Handle Payment Notifications

`NotificationHandler::handle()` is the recommended entry point — it parses,
unwraps, verifies and extracts in one call, and **fails closed** by throwing on a
missing or invalid signature.

```php
use Melaku\Telebirr\NotificationHandler;
use Melaku\Telebirr\Exceptions\TelebirrException;

try {
    $payment = NotificationHandler::handle(file_get_contents('php://input'), $config);
} catch (TelebirrException $e) {
    // respond* RETURN a NotificationResponse (no header()/echo). In a
    // framework, convert it to your Response object. In bare PHP, call send().
    NotificationHandler::respondError('Invalid signature', 401)->send();
    exit;
}

if ($payment['isSuccess']) {
    // Confirm server-to-server before fulfilling — see the settlement pattern above.
    $status = $client->getOrderStatus($payment['merchantOrderId']);
    if ($status->paid && $status->amount === $expectedAmount) {
        // Update database, fulfill order, etc. — idempotently.
    }
}

NotificationHandler::respondSuccess('Payment processed')->send();
```

The lower-level `parse()` / `verify()` / `isPaymentSuccessful()` /
`extractPaymentInfo()` calls remain available and unchanged if you need the steps
separately.

#### Notify parameters (the raw contract)

Telebirr POSTs a JSON body to your `notifyUrl`. **It does not use the same
conventions as the return URL** — the differences below are handled for you, but
they are the reason a hand-rolled notify handler tends to fail silently.

| Parameter | Meaning |
|---|---|
| `merch_order_id` | Your merchant order id, echoed back verbatim |
| `payment_order_id` | Telebirr's transaction reference |
| `transId` | Short transaction id — the one on the customer's SMS receipt |
| `trade_status` | **`Completed`** — *not* `PAY_SUCCESS` as on the return leg |
| `total_amount` | Order amount |
| `trans_currency` | Currency (`ETB`) |
| `trans_end_time`, `notify_time` | Epoch **milliseconds** — the return URL sends `Y-m-d H:i:s` strings instead |
| `merch_code`, `appid`, `notify_url` | Echoed back; part of the signed payload, so they must be kept when verifying |
| `sign`, `sign_type` | RSA-PSS signature over the other params |

Three consequences, all handled by `handle()`:

- **`Completed` is the success word on this leg.** `PaymentStatus::isSuccess()`
  accepts it. A handler that only checks `PAY_SUCCESS` will verify a genuine
  payment and then silently skip fulfillment — no error, no log line.
- **The body is sometimes wrapped in a `data` envelope.** `parse()` unwraps it.
  Left wrapped, both the order id and the signature are invisible, so the
  callback reads as unsigned *and* unmatched.
- **Timestamps are milliseconds.** `extractPaymentInfo()` returns the raw values
  under `timestamp` / `notifyTime` and normalized Unix seconds under
  `timestampUnix` / `notifyTimeUnix` (null for the return leg's formatted
  strings, which carry no timezone worth guessing at).

> **Framework usage:** instead of `->send()`, build a native response, e.g. in
> Laravel: `return response(json: $resp->getBody(), status: $resp->getStatusCode());`

### Query Order Status (low level)

Prefer `getOrderStatus()` above; the raw call remains available when you need
the untouched response:

```php
$tokenInfo = $client->applyFabricToken();
$orderStatus = $client->queryOrder($tokenInfo['token'], null, 'YOUR_ORDER_ID');

$tradeStatus = $orderStatus['biz_content']['trade_status'] ?? '';
if (strtoupper($tradeStatus) === 'PAY_SUCCESS') {
    // Payment successful
}
```

### Check gateway health

The sandbox can be flaky; probe it before a user-facing checkout if you want
to degrade gracefully:

```php
$health = $client->ping(); // never throws
if (!$health['ok']) {
    // show "payment temporarily unavailable" instead of a broken checkout
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
connection).

**The Telebirr test gateway serves an incomplete certificate chain** (leaf
only, missing intermediate), which used to fail verification with cURL error
60 and push people toward `'verifySsl' => false`. The library now **ships the
gateway's CA chain** (`src/certs/telebirr-ca.pem`): when system-store
verification fails with error 60 and no custom bundle was supplied, the
request is retried once against the bundled chain — so verification works out
of the box. The bundled chain can only validate hosts issued under it (the
Telebirr gateways); it never loosens verification for anything else. If
verification still fails, the error explains the options.

```php
$config = Config::forProduction([
    // ... credentials ...
    'verifySsl'      => true,   // default true — leave on; the library warns (test) or
                                 // logs an error (production) if you turn it off
    'caBundlePath'   => null,   // optional path to a custom CA bundle (PEM);
                                 // supplying one disables the bundled-CA fallback
    'timeout'        => 30,     // total request timeout (seconds)
    'connectTimeout' => 10,     // connection timeout (seconds)
]);
```

### Token caching

`createCheckoutUrl()` and `getOrderStatus()` cache the fabric token until its
`expirationDate` (minus a 60s safety margin) **within the client instance**
and reuse it, saving a gateway round-trip whenever one request performs
several calls (e.g. a settle path). A rejected token (HTTP 401) drops the
cache automatically. Note PHP's request lifecycle: the cache does not persist
across requests. Opt out for strictly stateless behavior:

```php
$client = new Telebirr($config, null, null, ['cacheFabricToken' => false]);
```

`applyFabricToken()` always performs a real network call (and refreshes the
cache), so existing manual flows are unaffected.

### Retrying transient gateway errors

The test gateway regularly throws transient infra errors (see the sandbox
note below). Retry is **opt-in** with exponential backoff:

```php
$client = new Telebirr($config, $logger, null, [
    'retry' => ['retries' => 2, 'delayMs' => 500, 'maxDelayMs' => 5000],
]);
```

Only failures where `ApiException::isTransient()` is true are retried: known
Telebirr infra codes (`49401024991` "southbound service unavailable"),
HTTP 502/503/504, and cURL timeouts/connection drops. Parameter or auth
errors fail immediately. The code list is
`ApiException::TRANSIENT_TELEBIRR_ERROR_CODES`.

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
all in one place. API failures throw `ApiException`, which now carries
Telebirr's parsed error envelope — no more `json_decode($e->getResponseBody())`:

```php
use Melaku\Telebirr\Exceptions\ApiException;

try {
    $client->createCheckoutUrl('Order 123', '100.00');
} catch (ApiException $e) {
    $e->getHttpStatus();       // e.g. 400
    $e->getTelebirrCode();     // e.g. '49401024991' — parsed from the body
    $e->getTelebirrMessage();  // Telebirr's errorMsg
    $e->getTelebirrSolution(); // Telebirr's errorSolution remediation text
    $e->isTransient();         // true for retryable gateway-side failures
    $e->getResponseBody();     // raw body, if you need it
}
```

### Amounts & rounding

`amount` accepts `string|int|float` and is formatted to exactly 2 decimals —
Telebirr's wire format for ETB. If you store amounts in minor units (cents),
divide before passing (`$cents / 100`). Prefer passing a **string**
(`'100.50'`) when the value came from user input or a DB decimal column,
sidestepping binary floating-point surprises.

### ⚠️ Sandbox instability

The **test gateway is frequently unstable** and returns transient infra
errors that look exactly like integration bugs — most commonly:

```
errorCode 49401024991: "southbound business service is unavailable"
```

If your request worked before and suddenly throws a `4940…` code with an
`errorSolution` suggesting a retry, **it's the gateway, not your code**.
Wait and retry (or enable the `retry` option above). Don't spend an hour
debugging a correct integration.

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
