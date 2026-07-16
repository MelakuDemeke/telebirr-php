# Changelog

All notable changes to `melaku/telebirr` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/); versions follow [SemVer](https://semver.org/).

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
