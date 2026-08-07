# Changelog

All notable changes to `melaku/telebirr` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/); versions follow [SemVer](https://semver.org/).

## [2.3.0] — 2026-08-07

The notify leg, corrected against a live production notification body. Fully
backward compatible with 2.2.0 — existing keys and method signatures are
unchanged; everything below is either a new method or a new array key.

The theme: **the server-to-server notification does not speak the same dialect as
the return URL or queryOrder**, and every one of those differences fails the same
silent way. The signature verifies, the payment is genuinely complete, and
fulfillment simply never runs — no exception, no error, no log line. It is
indistinguishable from a callback that never arrived, which is exactly why it
took so long to spot.

### Fixed
- **`trade_status: Completed` now reads as a successful payment.** The notify leg
  reports `Completed` where the return URL and `queryOrder` report `PAY_SUCCESS`;
  `PaymentStatus::isSuccess()` only knew the latter, so `isPaymentSuccessful()`
  returned `false` for a verified, genuinely paid notification. This is the fix
  that matters — the rest is ergonomics around it.
- **`NotificationHandler::parse()` unwraps a `data` envelope** when Telebirr wraps
  the body in one. Left wrapped, `merch_order_id` and `sign` are both absent as
  far as every helper is concerned, so the callback reads as unsigned *and*
  matching no order. Flat bodies (the common shape) pass through untouched — the
  envelope is only unwrapped when the inner array actually carries
  `merch_order_id`, so a payload with an unrelated `data` key is not mangled.

### Added
- **`NotificationHandler::handle($rawJson, $config)`** — parse, unwrap, verify and
  extract in one call, mirroring `ReturnUrlHandler::handle()`. Fails closed:
  throws `TelebirrException` on a missing or invalid signature rather than
  returning something that looks usable. Returns `extractPaymentInfo()` plus an
  `isSuccess` boolean.
- **`NotificationHandler::unwrap()`** — the envelope logic on its own, for
  integrators who call `parse()`/`verify()` separately.
- **`extractPaymentInfo()` now returns `transId`** — Telebirr's short transaction
  id, the one printed on the customer's SMS receipt and the one they quote to
  your support desk. It was being parsed and thrown away.
- **`extractPaymentInfo()` also returns `merchCode`, `appId`, `notifyUrl` and
  `raw`.** `notifyUrl` is the callback URL Telebirr echoes back — the first thing
  worth logging when notifications are not arriving.
- **`NotificationHandler::toUnixSeconds()`**, plus `timestampUnix` /
  `notifyTimeUnix` keys on `extractPaymentInfo()`. The notify leg sends epoch
  **milliseconds** (`1784756474676`) where the return URL sends a formatted
  `Y-m-d H:i:s` string. Non-numeric values yield `null` rather than a guess: the
  formatted strings carry no timezone, and assuming one would quietly shift every
  timestamp.

### Notes
- Signature verification was **not** changed. A real notification body verifies
  under the existing RSA-PSS path, over a canonical string that includes the
  non-standard `transId` and `notify_url` fields — confirmed end to end against a
  live payload, so no PKCS#1 fallback is warranted.
- 23 new tests cover the dialect, the envelope, timestamp normalization, and
  `handle()`'s fail-closed behaviour on tampered and unsigned bodies.

## [2.2.0] — 2026-07-16

Driven by field notes from a real integration (same batch as telebirr-js 3.1.0).
Fully backward compatible with 2.1.0.

### Added
- **Key auto-normalization**: `privateKey`/`telebirrPublicKey` now accept bare base64 DER
  (the format Ethio Telecom actually issues) as well as PEM — including PEM with literal
  `\n` from env files. The right header (PKCS#8/PKCS#1, SPKI) is detected automatically.
  This logic previously lived only in the examples' bootstrap; it is now in the library
  (`Melaku\Telebirr\KeyNormalizer`), where every integrator benefits.
- **Bundled Telebirr CA chain** (`src/certs/telebirr-ca.pem`): the test gateway serves an
  incomplete TLS chain; when system-store verification fails with cURL error 60 and no
  custom bundle was supplied, `CurlHttpClient` retries once against the bundled chain —
  TLS verification works out of the box, and `'verifySsl' => false` should never be
  needed. Verification failures now explain themselves and point at the fix.
- **Structured gateway errors**: `ApiException` now exposes `getTelebirrCode()`,
  `getTelebirrMessage()`, and `getTelebirrSolution()` parsed from Telebirr's error
  envelope; `getErrorCode()` is backfilled from the body when present.
  `ApiException::isTransient()` identifies retryable failures.
- **Opt-in retry with backoff**: `new Telebirr($config, $logger, $http, ['retry' =>
  ['retries' => 2]])` retries transient failures (Telebirr infra codes such as
  `49401024991`, HTTP 502/503/504, cURL timeouts) with exponential backoff. Off by default.
- **`getOrderStatus($merchOrderId, $prepayId = null)`**: high-level, server-to-server
  order verification returning a typed `OrderStatus` value object
  (`paid`/`failed`/`cancelled`/`tradeStatus`/`amount`/`currency`/`paymentOrderId`/
  `merchOrderId`/`transEndTime`/`raw`) — the settlement counterpart to `createCheckoutUrl`.
- **Fabric token caching**: tokens are cached until `expirationDate` (minus a 60s margin)
  within the client instance and reused by the high-level helpers; a 401 invalidates the
  cache. Note PHP's request lifecycle — the cache helps when one request performs several
  gateway calls. Opt out with `['cacheFabricToken' => false]`.
- **`ping()`**: never-throwing gateway health probe (`['ok', 'latencyMs', 'error']`).
- **Construction-time warnings**: unreachable `notifyUrl` (localhost/private/http), and
  `'verifySsl' => false` (warning on test, error-level on production).
- **`Config::fromEnvironment()` zero-config**: now reads `TELEBIRR_FABRIC_APP_ID`,
  `TELEBIRR_APP_SECRET`, `TELEBIRR_MERCHANT_APP_ID`, `TELEBIRR_MERCHANT_CODE`,
  `TELEBIRR_PRIVATE_KEY`, `TELEBIRR_NOTIFY_URL`, `TELEBIRR_REDIRECT_URL`,
  `TELEBIRR_PUBLIC_KEY` from `$_ENV`/`$_SERVER`/`getenv()` (explicit options still win).
  The options argument is now optional.
- **Test suite**: dependency-free runner at `tests/run.php` (`php tests/run.php`).

### Docs
- Exact return-URL parameters and the notify acknowledgement/retry contract.
- Reference idempotent settlement pattern (return ↔ notify race, compare-and-set grant).
- Sandbox-instability note (`49401024991` is gateway-side — retry, don't debug).
- Amount rounding / minor-units guidance.

## [2.1.0]

- Modern API baseline: `Config` named constructors, injectable `HttpClientInterface`/PSR-3
  logger, fail-closed `ReturnUrlHandler`/`NotificationHandler`, `CheckoutResult` exposing
  the exact `merchOrderId`, TLS verification and timeouts by default.
