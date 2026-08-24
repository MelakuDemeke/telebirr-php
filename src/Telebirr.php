<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Melaku\Telebirr\Http\HttpClientInterface;
use Melaku\Telebirr\Http\CurlHttpClient;
use Melaku\Telebirr\Http\HttpClientException;
use Melaku\Telebirr\Exceptions\ApiException;
use Melaku\Telebirr\Exceptions\InvalidParameterException;

/**
 * Telebirr Web Checkout client (modern H5 C2B API).
 */
class Telebirr
{
	/** Refresh the cached fabric token this many seconds before its reported expiry. */
	private const TOKEN_EXPIRY_SAFETY_MARGIN = 60;

	/** Cache TTL (seconds) when the token response carries no parseable expirationDate. */
	private const TOKEN_FALLBACK_TTL = 300;

	private Config $config;
	private Signer $signer;
	private LoggerInterface $logger;
	private HttpClientInterface $httpClient;

	/**
	 * Client behavior options:
	 * - 'retry' => ['retries' => int, 'delayMs' => int, 'maxDelayMs' => int]
	 *   Opt-in retry with exponential backoff on transient failures (Telebirr
	 *   infra codes like 49401024991, HTTP 502/503/504, transport timeouts).
	 *   Default: no retry.
	 * - 'cacheFabricToken' => bool  Cache the fabric token until expirationDate
	 *   (minus a safety margin) within this instance. Default true. Note PHP's
	 *   request lifecycle: the cache lives for the current request only — it
	 *   helps when one request performs several gateway calls (e.g. a settle
	 *   path), not across requests.
	 */
	private array $options;

	/** @var array{token: string, expiresAt: int}|null */
	private ?array $tokenCache = null;

	/**
	 * @param Config                    $config
	 * @param LoggerInterface|null      $logger     Any PSR-3 logger (Monolog, Laravel, ...). Defaults to a no-op.
	 * @param HttpClientInterface|null  $httpClient Injectable HTTP client. Defaults to a cURL client that
	 *                                              verifies TLS and applies timeouts using the Config settings.
	 * @param array|null                $options    Client behavior: opt-in transient-error retry, token caching.
	 */
	public function __construct(
		Config $config,
		?LoggerInterface $logger = null,
		?HttpClientInterface $httpClient = null,
		?array $options = null
	) {
		$this->config = $config;
		$this->signer = new Signer($config);
		$this->logger = $logger ?? new NullLogger();
		$this->options = $options ?? [];
		$this->httpClient = $httpClient ?? new CurlHttpClient(
			$config->verifySsl,
			$config->caBundlePath,
			$config->timeout,
			$config->connectTimeout
		);

		$this->warnOnRiskyConfig();
	}

	/**
	 * Surface configuration footguns loudly at construction time.
	 */
	private function warnOnRiskyConfig(): void
	{
		if (!$this->config->verifySsl) {
			if ($this->config->isProduction()) {
				$this->logger->error(
					'verifySsl=false is set against the PRODUCTION gateway — TLS verification is disabled '
					. 'for a payment gateway. Remove verifySsl=false: this library bundles the Telebirr CA, '
					. 'so verification works without it.'
				);
			} else {
				$this->logger->warning(
					'verifySsl=false — TLS verification is disabled. Acceptable only against the TEST gateway, never in production.'
				);
			}
		}

		$host = parse_url($this->config->notifyUrl, PHP_URL_HOST);
		$scheme = parse_url($this->config->notifyUrl, PHP_URL_SCHEME);
		if (is_string($host)) {
			$isUnreachable = $host === 'localhost'
				|| $host === '::1'
				|| preg_match('/^127\./', $host) === 1
				|| preg_match('/^10\./', $host) === 1
				|| preg_match('/^192\.168\./', $host) === 1
				|| preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) === 1
				|| substr($host, -6) === '.local'
				|| substr($host, -9) === '.internal';
			if ($isUnreachable) {
				$this->logger->warning(
					"notifyUrl '{$this->config->notifyUrl}' points at localhost/a private address — Telebirr's "
					. 'servers cannot reach it, so the server-to-server payment notification will never arrive. '
					. 'Use a publicly reachable URL (in development, a tunnel such as ngrok or cloudflared).'
				);
			} elseif ($scheme === 'http') {
				$this->logger->warning(
					"notifyUrl '{$this->config->notifyUrl}' uses plain http:// — use https:// so payment "
					. 'notifications cannot be intercepted.'
				);
			}
		}
	}

	/**
	 * Set the PSR-3 logger for API request/response logging.
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * Step 1: Apply fabric token.
	 *
	 * - Endpoint: POST /payment/v1/token
	 * - Headers: Content-Type: application/json, X-APP-Key: {fabricAppId}
	 * - Body: { "appSecret": "{appSecret}" }
	 * - Response: { "token": "Bearer xxx", "effectiveDate": "...", "expirationDate": "..." }
	 *
	 * Always performs a network call. The high-level helpers
	 * (createCheckoutUrl, getOrderStatus) reuse a cached token until its
	 * expiry instead — see the 'cacheFabricToken' constructor option.
	 *
	 * @return array { token, effectiveDate, expirationDate, ... }
	 * @throws ApiException on API errors or invalid responses
	 */
	public function applyFabricToken(): array
	{
		$url = $this->config->baseUrl . '/payment/v1/token';

		$result = $this->sendApiRequest(
			'applyFabricToken',
			$url,
			['appSecret' => $this->config->appSecret],
			null,
			false // token endpoint does not require biz_content wrapper handling
		);

		// Validate that token exists in response
		if (empty($result['token'])) {
			throw new ApiException(
				'Token missing in API response. Response: ' . json_encode($result),
				200,
				null,
				json_encode($result)
			);
		}

		if (($this->options['cacheFabricToken'] ?? true) !== false) {
			$expiresAt = self::parseExpiryTimestamp($result['expirationDate'] ?? null);
			$this->tokenCache = [
				'token'     => (string) $result['token'],
				'expiresAt' => $expiresAt !== null
					? $expiresAt - self::TOKEN_EXPIRY_SAFETY_MARGIN
					: time() + self::TOKEN_FALLBACK_TTL,
			];
		}

		return $result;
	}

	/**
	 * Cached fabric token when still valid; otherwise fetch (and cache) a fresh one.
	 */
	private function getFabricToken(): string
	{
		if (
			($this->options['cacheFabricToken'] ?? true) !== false
			&& $this->tokenCache !== null
			&& time() < $this->tokenCache['expiresAt']
		) {
			return $this->tokenCache['token'];
		}

		$tokenInfo = $this->applyFabricToken();
		return (string) $tokenInfo['token'];
	}

	/**
	 * Parse Telebirr's expirationDate (epoch seconds/millis, numeric string,
	 * or date string) to an epoch-seconds timestamp.
	 *
	 * @param mixed $value
	 */
	private static function parseExpiryTimestamp($value): ?int
	{
		if (is_int($value) || is_float($value)) {
			$numeric = (float) $value;
			return $numeric > 1e12 ? (int) ($numeric / 1000) : (int) $numeric;
		}
		if (is_string($value) && trim($value) !== '') {
			$trimmed = trim($value);
			if (preg_match('/^\d+$/', $trimmed) === 1) {
				$numeric = (float) $trimmed;
				return $numeric > 1e12 ? (int) ($numeric / 1000) : (int) $numeric;
			}
			$parsed = strtotime($trimmed);
			if ($parsed !== false) {
				return $parsed;
			}
		}
		return null;
	}

	/**
	 * Step 2: Request create order (preOrder) – Telebirr H5 C2B requestCreateOrder.
	 *
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder
	 *
	 * @param string $fabricToken "Bearer xxx" from applyFabricToken()
	 * @param string $title Order title (auto-sanitized to Telebirr's allowed charset)
	 * @param string|int|float $amount Total amount (ETB) - formatted to 2 decimals
	 * @param string|null $merchOrderId Optional merchant order ID; must be alphanumeric
	 *        (^[A-Za-z0-9]+$). If null, one is generated. Invalid ids throw — they are NOT
	 *        silently rewritten (Telebirr would otherwise strip characters and break lookups).
	 *
	 * @return array Full API response (contains biz_content.prepay_id on success)
	 * @throws ApiException on API errors, invalid responses, or missing prepay_id
	 * @throws InvalidParameterException on parameter validation failures
	 */
	public function createOrder(string $fabricToken, string $title, $amount, ?string $merchOrderId = null): array
	{
		// Validate parameters. The order id fails loud on invalid input (autoSanitize = false);
		// the title is a display string, so it is sanitized to the allowed charset.
		try {
			$title = ParameterValidator::validateTitle($title, true);
			$amount = ParameterValidator::validateAmount($amount);
			$merchOrderId = ParameterValidator::validateMerchantOrderId($merchOrderId, false);
		} catch (InvalidParameterException $e) {
			$this->logger->error('Parameter validation failed in createOrder', [
				'parameter' => $e->getParameterName(),
				'value' => $e->getParameterValue(),
				'message' => $e->getMessage()
			]);
			throw $e;
		}

		$reqObject = $this->buildPreOrderRequest($title, $amount, $merchOrderId);
		$url = $this->config->baseUrl . '/payment/v1/merchant/preOrder';

		$result = $this->sendApiRequest('createOrder', $url, $reqObject, $fabricToken);

		// Validate prepay_id in response (per requestCreateOrder spec)
		if (
			!isset($result['biz_content']) ||
			!is_array($result['biz_content']) ||
			empty($result['biz_content']['prepay_id'])
		) {
			throw new ApiException(
				'prepay_id missing in create order response. Response: ' . json_encode($result),
				200,
				null,
				json_encode($result)
			);
		}

		return $result;
	}

	/**
	 * Request create order (createOrder) for the In-App SDK flow – trade_type "InApp".
	 *
	 * Used when a mobile app's Telebirr SDK initiates the payment. Unlike the web
	 * checkout flow, there is no checkout URL: the response's `receiveCode` must be
	 * passed to the mobile SDK to continue the payment.
	 *
	 * @param string $fabricToken "Bearer xxx" from applyFabricToken()
	 * @param string $title Order title (auto-sanitized to Telebirr's allowed charset)
	 * @param string|int|float $amount Total amount (ETB) - formatted to 2 decimals
	 * @param string|null $merchOrderId Optional merchant order ID; must be alphanumeric
	 *        (^[A-Za-z0-9]+$). If null, one is generated. Invalid ids throw — they are NOT
	 *        silently rewritten.
	 *
	 * @return array Full API response (contains biz_content.receiveCode on success)
	 * @throws ApiException on API errors, invalid responses, or missing receiveCode
	 * @throws InvalidParameterException on parameter validation failures
	 */
	public function createInAppOrder(string $fabricToken, string $title, $amount, ?string $merchOrderId = null): array
	{
		try {
			$title = ParameterValidator::validateTitle($title, true);
			$amount = ParameterValidator::validateAmount($amount);
			$merchOrderId = ParameterValidator::validateMerchantOrderId($merchOrderId, false);
		} catch (InvalidParameterException $e) {
			$this->logger->error('Parameter validation failed in createInAppOrder', [
				'parameter' => $e->getParameterName(),
				'value' => $e->getParameterValue(),
				'message' => $e->getMessage()
			]);
			throw $e;
		}

		$reqObject = $this->buildInAppOrderRequest($title, $amount, $merchOrderId);
		$url = $this->config->baseUrl . '/payment/v1/inapp/createOrder';

		$result = $this->sendApiRequest('createInAppOrder', $url, $reqObject, $fabricToken);

		if (
			!isset($result['biz_content']) ||
			!is_array($result['biz_content']) ||
			empty($result['biz_content']['receiveCode'])
		) {
			throw new ApiException(
				'receiveCode missing in in-app order response. Response: ' . json_encode($result),
				200,
				null,
				json_encode($result)
			);
		}

		return $result;
	}

	/**
	 * Step 3: Generate checkout URL from prepay_id – Telebirr H5 C2B Generate_Check_Url.
	 *
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Generate_Check_Url
	 *
	 * @param string $prepayId The prepay_id obtained from createOrder() response (biz_content.prepay_id)
	 * @return string Complete checkout URL ready for user redirect
	 */
	public function buildCheckoutUrl(string $prepayId): string
	{
		$rawRequest = $this->buildRawCheckoutRequest($prepayId);

		return $this->config->webBaseUrl . $rawRequest . '&version=1.0&trade_type=Checkout';
	}

	/**
	 * Query order status – Telebirr H5 C2B queryOrder.
	 *
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/queryOrder
	 *
	 * Use this for a server-to-server confirmation of a payment's real status,
	 * instead of trusting the (spoofable) parameters on a browser return URL.
	 *
	 * @param string $fabricToken "Bearer xxx" from applyFabricToken()
	 * @param string|null $prepayId Optional: prepay_id from createOrder response
	 * @param string|null $merchOrderId Optional: merchant order ID (at least one must be provided)
	 *
	 * @return array Full API response (contains biz_content with order status on success)
	 * @throws ApiException on API errors or invalid responses
	 * @throws InvalidParameterException if neither id is provided, or on validation failure
	 */
	public function queryOrder(string $fabricToken, ?string $prepayId = null, ?string $merchOrderId = null): array
	{
		if (empty($prepayId) && empty($merchOrderId)) {
			throw new InvalidParameterException(
				'prepayId|merchOrderId',
				null,
				'Either prepayId or merchOrderId must be provided'
			);
		}

		if (!empty($merchOrderId)) {
			try {
				$merchOrderId = ParameterValidator::validateMerchantOrderId($merchOrderId, false);
			} catch (InvalidParameterException $e) {
				$this->logger->error('Parameter validation failed in queryOrder', [
					'parameter' => $e->getParameterName(),
					'value' => $e->getParameterValue(),
					'message' => $e->getMessage()
				]);
				throw $e;
			}
		}

		$reqObject = $this->buildQueryOrderRequest($prepayId, $merchOrderId);
		$url = $this->config->baseUrl . '/payment/v1/merchant/queryOrder';

		return $this->sendApiRequest('queryOrder', $url, $reqObject, $fabricToken);
	}

	/**
	 * Refund order – Telebirr H5 C2B RefundOrder.
	 *
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/RefundOrder
	 *
	 * @param string $fabricToken "Bearer xxx" from applyFabricToken()
	 * @param string|int|float $refundAmount Refund amount (ETB) - formatted to 2 decimals
	 * @param string|null $paymentOrderId Optional: payment_order_id from Telebirr
	 * @param string|null $merchOrderId Optional: merchant order ID (at least one must be provided)
	 * @param string|null $refundReason Optional: reason for refund
	 * @param string|null $refundOrderId Optional: refund_request_no (auto-generated if null)
	 *
	 * @return array Full API response (contains biz_content with refund status on success)
	 * @throws ApiException on API errors or invalid responses
	 * @throws InvalidParameterException if neither id is provided, or on validation failure
	 */
	public function refundOrder(string $fabricToken, $refundAmount, ?string $paymentOrderId = null, ?string $merchOrderId = null, ?string $refundReason = null, ?string $refundOrderId = null): array
	{
		if (($paymentOrderId === null || $paymentOrderId === '') && ($merchOrderId === null || $merchOrderId === '')) {
			throw new InvalidParameterException(
				'paymentOrderId|merchOrderId',
				null,
				'Either paymentOrderId or merchOrderId must be provided'
			);
		}

		try {
			$refundAmount = ParameterValidator::validateAmount($refundAmount);
			if (!empty($merchOrderId)) {
				$merchOrderId = ParameterValidator::validateMerchantOrderId($merchOrderId, false);
			}
			if (!empty($refundOrderId)) {
				$refundOrderId = ParameterValidator::validateMerchantOrderId($refundOrderId, false);
			}
		} catch (InvalidParameterException $e) {
			$this->logger->error('Parameter validation failed in refundOrder', [
				'parameter' => $e->getParameterName(),
				'value' => $e->getParameterValue(),
				'message' => $e->getMessage()
			]);
			throw $e;
		}

		$reqObject = $this->buildRefundOrderRequest($refundAmount, $paymentOrderId, $merchOrderId, $refundReason, $refundOrderId);
		$url = $this->config->baseUrl . '/payment/v1/merchant/refund';

		try {
			return $this->sendApiRequest('refundOrder', $url, $reqObject, $fabricToken);
		} catch (ApiException $e) {
			// Augment refund-specific failures with actionable guidance, preserving the typed exception.
			$hint = $this->refundErrorHint($e, $url);
			if ($hint === '') {
				throw $e;
			}
			throw new ApiException(
				$e->getMessage() . $hint,
				$e->getHttpStatus(),
				$e->getErrorCode(),
				$e->getResponseBody(),
				(int) $e->getCode(),
				$e
			);
		}
	}

	/**
	 * High-level helper: applyFabricToken + createOrder + buildCheckoutUrl.
	 *
	 * Returns a {@see CheckoutResult} carrying the checkout URL AND the exact
	 * merch_order_id that was sent to Telebirr. Persist that id against your
	 * order — it is the value Telebirr echoes back in notifications and on the
	 * return URL, so storing anything else risks a lookup miss.
	 *
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/%20CheckOut
	 *
	 * @param string $title Order title (auto-sanitized)
	 * @param string|int|float $amount Total amount (ETB)
	 * @param string|null $merchOrderId Optional merchant order ID (^[A-Za-z0-9]+$). Generated if null.
	 *        Invalid ids throw rather than being silently rewritten.
	 *
	 * @return CheckoutResult { checkoutUrl, merchOrderId, prepayId }
	 * @throws ApiException on API errors
	 * @throws InvalidParameterException on parameter validation failures
	 */
	public function createCheckoutUrl(string $title, $amount, ?string $merchOrderId = null): CheckoutResult
	{
		// Resolve the id up-front (generate if null, throw if invalid) so we can
		// report back the EXACT value Telebirr will use.
		$merchOrderId = ParameterValidator::validateMerchantOrderId($merchOrderId, false);

		$fabricToken = $this->getFabricToken();
		$order = $this->createOrder($fabricToken, $title, $amount, $merchOrderId);
		$prepayId = $order['biz_content']['prepay_id'];
		$checkoutUrl = $this->buildCheckoutUrl($prepayId);

		return new CheckoutResult($checkoutUrl, $merchOrderId, $prepayId);
	}

	/**
	 * High-level helper: confirm an order's real status server-to-server.
	 * Symmetric counterpart to createCheckoutUrl() — token management (with
	 * caching) and response mapping are handled for you.
	 *
	 * This is what your notify endpoint AND your return-URL handler should
	 * call before granting anything: never trust the spoofable browser
	 * redirect, and verify OrderStatus::$amount against your own order before
	 * fulfilling.
	 *
	 * @param string|null $merchOrderId Your merchant order id (from CheckoutResult::getMerchOrderId()).
	 * @param string|null $prepayId Optional alternative lookup key; at least one of the two is required.
	 * @throws ApiException on API errors
	 * @throws InvalidParameterException if neither id is provided
	 */
	public function getOrderStatus(?string $merchOrderId = null, ?string $prepayId = null): OrderStatus
	{
		$fabricToken = $this->getFabricToken();
		$result = $this->queryOrder($fabricToken, $prepayId, $merchOrderId);

		$biz = (isset($result['biz_content']) && is_array($result['biz_content'])) ? $result['biz_content'] : [];

		// Defensive casing fallbacks: the gateway documents snake_case but has
		// been observed returning camelCase variants on some deployments.
		$pick = static function (array $keys) use ($biz): string {
			foreach ($keys as $key) {
				if (isset($biz[$key]) && $biz[$key] !== '' && $biz[$key] !== null) {
					return (string) $biz[$key];
				}
			}
			return '';
		};

		// The three legs do not share a vocabulary. queryOrder answers with
		// **`order_status`** where the notify sends `trade_status: Completed`
		// and the return sends `trade_status: PAY_SUCCESS`. Reading only
		// `trade_status` here left $tradeStatus empty for a genuinely paid
		// order, so `paid` came back false with no error to notice -- the same
		// silent-failure shape as the notify dialect bug fixed in 2.3.0.
		// Confirmed against production merchant 500289 on 2026-08-21.
		$tradeStatus = $pick(['trade_status', 'tradeStatus', 'order_status', 'orderStatus']);
		$paymentOrderId = $pick(['payment_order_id', 'paymentOrderId']);
		// queryOrder calls the timestamp `trans_time`, not `trans_end_time`.
		$transEndTime = $pick(['trans_end_time', 'transEndTime', 'trans_time', 'transTime']);
		$currency = $pick(['trans_currency', 'transCurrency']);
		// queryOrder spells the transaction id `trans_id`; the notify leg sends
		// the same value as `transId`. Accept either.
		$transId = $pick(['trans_id', 'transId']);

		return new OrderStatus(
			$tradeStatus !== '' && PaymentStatus::isSuccess($tradeStatus),
			$tradeStatus !== '' && PaymentStatus::isFailure($tradeStatus),
			$tradeStatus !== '' && PaymentStatus::isCancelled($tradeStatus),
			$tradeStatus,
			$pick(['total_amount', 'totalAmount']),
			$currency !== '' ? $currency : 'ETB',
			$paymentOrderId !== '' ? $paymentOrderId : null,
			$pick(['merch_order_id', 'merchOrderId']) !== '' ? $pick(['merch_order_id', 'merchOrderId']) : (string) $merchOrderId,
			$transEndTime !== '' ? $transEndTime : null,
			$result,
			$transId
		);
	}

	/**
	 * Probe gateway availability by requesting a fabric token (the cheapest
	 * authenticated call). Useful before a user-facing checkout, given how
	 * flaky the sandbox can be. Never throws.
	 *
	 * @return array{ok: bool, latencyMs: int, error: ?string}
	 */
	public function ping(): array
	{
		$start = microtime(true);
		try {
			$this->applyFabricToken();
			return ['ok' => true, 'latencyMs' => (int) round((microtime(true) - $start) * 1000), 'error' => null];
		} catch (\Throwable $e) {
			return ['ok' => false, 'latencyMs' => (int) round((microtime(true) - $start) * 1000), 'error' => $e->getMessage()];
		}
	}

	/**
	 * Shared request pipeline with opt-in retry: transient failures (see
	 * ApiException::isTransient()) are retried with exponential backoff when
	 * options['retry']['retries'] > 0; everything else fails immediately.
	 *
	 * @throws ApiException on transport failure, non-2xx status, invalid JSON, or API error code.
	 */
	private function sendApiRequest(
		string $operation,
		string $url,
		array $reqObject,
		?string $fabricToken = null,
		bool $checkApiCode = true
	): array {
		$retrySettings = $this->options['retry'] ?? [];
		$retries = max(0, (int) ($retrySettings['retries'] ?? 0));
		$baseDelayMs = (int) ($retrySettings['delayMs'] ?? 500);
		$maxDelayMs = (int) ($retrySettings['maxDelayMs'] ?? 5000);

		for ($attempt = 0; ; $attempt++) {
			try {
				return $this->sendApiRequestOnce($operation, $url, $reqObject, $fabricToken, $checkApiCode);
			} catch (ApiException $e) {
				if ($attempt < $retries && $e->isTransient()) {
					$delayMs = (int) min($baseDelayMs * (2 ** $attempt), $maxDelayMs);
					$this->logger->warning(
						"Transient {$operation} failure (attempt " . ($attempt + 1) . '/' . ($retries + 1) . "), retrying in {$delayMs}ms",
						['telebirr_code' => $e->getTelebirrCode(), 'http_status' => $e->getHttpStatus()]
					);
					usleep($delayMs * 1000);
					continue;
				}
				throw $e;
			}
		}
	}

	/**
	 * One request attempt: sign is already applied to $reqObject by the caller's builder.
	 * Handles transport, HTTP status, JSON decoding and API-level error detection.
	 *
	 * @param string      $operation   Human label used in logs/errors (e.g. 'createOrder').
	 * @param string      $url         Absolute endpoint URL.
	 * @param array       $reqObject   Request body to JSON-encode.
	 * @param string|null $fabricToken Optional bearer token for the Authorization header.
	 * @param bool        $checkApiCode Whether to treat a non-success `code` field as an error.
	 * @return array Decoded response body.
	 * @throws ApiException on transport failure, non-2xx status, invalid JSON, or API error code.
	 */
	private function sendApiRequestOnce(
		string $operation,
		string $url,
		array $reqObject,
		?string $fabricToken = null,
		bool $checkApiCode = true
	): array {
		$headers = [
			'Content-Type: application/json',
			'X-APP-Key: ' . $this->config->fabricAppId,
		];
		if ($fabricToken !== null) {
			$headers[] = 'Authorization: ' . $fabricToken;
		}

		$this->logRequest($operation, $url, $reqObject);

		try {
			$response = $this->httpClient->post($url, $headers, (string) json_encode($reqObject));
		} catch (HttpClientException $e) {
			$errorMsg = "Failed to call {$operation} API: " . $e->getMessage();
			$this->logger->error($errorMsg);
			throw new ApiException($errorMsg, null, null, null, (int) $e->getCode(), $e);
		}

		$httpCode = $response->getStatusCode();
		$responseBody = $response->getBody();

		$this->logResponse($operation, $httpCode, $responseBody);

		if ($httpCode < 200 || $httpCode >= 300) {
			if ($httpCode === 401) {
				// Token was rejected — drop the cache so the next call fetches fresh.
				$this->tokenCache = null;
			}
			$errorMsg = $this->formatApiError($operation, $httpCode, $responseBody);
			$this->logger->error($errorMsg, ['http_code' => $httpCode]);
			throw new ApiException($errorMsg, $httpCode, null, $responseBody);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			$errorMsg = "Invalid {$operation} API response (not JSON): " . $responseBody;
			$this->logger->error($errorMsg);
			throw new ApiException($errorMsg, $httpCode, null, $responseBody);
		}

		if ($checkApiCode && isset($result['code']) && $result['code'] !== '00000' && $result['code'] !== '0') {
			$errorMsg = $this->formatApiErrorResponse($operation, $result);
			$this->logger->error($errorMsg, ['error_code' => $result['code']]);
			throw new ApiException($errorMsg, $httpCode, (string) $result['code'], $responseBody);
		}

		return $result;
	}

	/**
	 * Build actionable guidance for known refund failure modes. Returns '' when none applies.
	 */
	private function refundErrorHint(ApiException $e, string $url): string
	{
		if ($e->getHttpStatus() === 404) {
			return "\n\n⚠️ 404 Error - Endpoint Not Found\n"
				. "The refund endpoint might not be available for your account.\n\n"
				. "Current endpoint being called: " . $url . "\n\n"
				. "Please verify:\n"
				. "1. The official RefundOrder documentation:\n"
				. "   https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/RefundOrder\n"
				. "2. That the RefundOrder API is enabled for your account\n"
				. "3. That you're using the correct base URL (dev vs production)\n"
				. "4. Contact Telebirr support if refunds are not available for your account.";
		}

		$errorCode = $e->getErrorCode() ?? '';
		if ($errorCode === '60320025' || strpos($e->getMessage(), 'failed to call the payment platform') !== false) {
			return "\n\n⚠️ This error typically indicates:\n"
				. "1. A development/sandbox environment where refunds are not enabled\n"
				. "2. Your account may not have refund permissions enabled\n"
				. "3. The original payment may not be eligible for refund (not completed, too old, etc.)\n"
				. "4. You may need to use the production environment for refunds";
		}

		return '';
	}

	/**
	 * Internal: build preOrder request body per requestCreateOrder spec.
	 */
	private function buildPreOrderRequest(string $title, string $amount, string $merchOrderId): array
	{
		$req = [
			'timestamp' => Signer::createTimeStamp(),
			'nonce_str' => Signer::createNonceStr(),
			'method'    => 'payment.preorder',
			'version'   => '1.0',
		];

		$biz = [
			'notify_url'      => $this->config->notifyUrl,
			'appid'           => $this->config->merchantAppId,
			'merch_code'      => $this->config->merchantCode,
			'merch_order_id'  => $merchOrderId,
			'trade_type'      => 'Checkout',
			'title'           => $title,
			'total_amount'    => $amount,
			'trans_currency'  => 'ETB',
			'timeout_express' => '120m',
		];

		if ($this->config->redirectUrl !== null && !empty($this->config->redirectUrl)) {
			$biz['redirect_url'] = $this->config->redirectUrl;
		}

		$req['biz_content'] = $biz;

		$req['sign']      = $this->signer->signRequestObject($req);
		$req['sign_type'] = 'SHA256WithRSA';

		return $req;
	}

	/**
	 * Internal: build the In-App SDK createOrder request body (trade_type "InApp").
	 */
	private function buildInAppOrderRequest(string $title, string $amount, string $merchOrderId): array
	{
		$req = [
			'timestamp' => Signer::createTimeStamp(),
			'nonce_str' => Signer::createNonceStr(),
			'method'    => 'payment.preorder',
			'version'   => '1.0',
		];

		$biz = [
			'notify_url'      => $this->config->notifyUrl,
			'appid'           => $this->config->merchantAppId,
			'merch_code'      => $this->config->merchantCode,
			'merch_order_id'  => $merchOrderId,
			'trade_type'      => 'InApp',
			'title'           => $title,
			'total_amount'    => $amount,
			'trans_currency'  => 'ETB',
			'timeout_express' => '120m',
		];

		$req['biz_content'] = $biz;

		$req['sign']      = $this->signer->signRequestObject($req);
		$req['sign_type'] = 'SHA256WithRSA';

		return $req;
	}

	/**
	 * Internal: build raw request string for the web checkout URL.
	 */
	private function buildRawCheckoutRequest(string $prepayId): string
	{
		$map = [
			'appid'     => $this->config->merchantAppId,
			'merch_code' => $this->config->merchantCode,
			'nonce_str' => Signer::createNonceStr(),
			'prepay_id' => $prepayId,
			'timestamp' => Signer::createTimeStamp(),
		];

		$sign = $this->signer->signRequestObject($map);

		$parts = [
			'appid=' . $map['appid'],
			'merch_code=' . $map['merch_code'],
			'nonce_str=' . $map['nonce_str'],
			'prepay_id=' . $map['prepay_id'],
			'timestamp=' . $map['timestamp'],
			'sign=' . $sign,
			'sign_type=SHA256WithRSA',
		];

		return implode('&', $parts);
	}

	/**
	 * Internal: build queryOrder request body per queryOrder spec.
	 */
	private function buildQueryOrderRequest(?string $prepayId, ?string $merchOrderId): array
	{
		$req = [
			'timestamp' => Signer::createTimeStamp(),
			'nonce_str' => Signer::createNonceStr(),
			'method'    => 'payment.queryorder',
			'version'   => '1.0',
		];

		$biz = [
			'appid'      => $this->config->merchantAppId,
			'merch_code' => $this->config->merchantCode,
		];

		if (!empty($prepayId)) {
			$biz['prepay_id'] = $prepayId;
		}

		if (!empty($merchOrderId)) {
			$biz['merch_order_id'] = $merchOrderId;
		}

		$req['biz_content'] = $biz;

		$req['sign']      = $this->signer->signRequestObject($req);
		$req['sign_type'] = 'SHA256WithRSA';

		return $req;
	}

	/**
	 * Internal: build refundOrder request body per RefundOrder spec.
	 */
	private function buildRefundOrderRequest(string $refundAmount, ?string $paymentOrderId, ?string $merchOrderId, ?string $refundReason, ?string $refundOrderId): array
	{
		$req = [
			'timestamp' => Signer::createTimeStamp(),
			'nonce_str' => Signer::createNonceStr(),
			'method'    => 'payment.refund',
			'version'   => '1.0',
		];

		// Generate refund_request_no (required) - use provided refundOrderId or auto-generate
		$refundRequestNo = !empty($refundOrderId)
			? $refundOrderId
			: ParameterValidator::generateMerchantOrderId();

		$biz = [
			'appid'        => $this->config->merchantAppId,
			'merch_code'   => $this->config->merchantCode,
			'refund_amount' => $refundAmount,
			'refund_request_no' => $refundRequestNo,
		];

		if (!empty($paymentOrderId)) {
			$biz['payment_order_id'] = $paymentOrderId;
		}

		if (!empty($merchOrderId)) {
			$biz['merch_order_id'] = $merchOrderId;
		}

		if (!empty($refundReason)) {
			$biz['refund_reason'] = $refundReason;
		}

		if ($refundOrderId !== null && $refundOrderId !== '') {
			$biz['refund_order_id'] = $refundOrderId;
		}

		$req['biz_content'] = $biz;

		$req['sign']      = $this->signer->signRequestObject($req);
		$req['sign_type'] = 'SHA256WithRSA';

		return $req;
	}

	/**
	 * Generate a valid merchant order ID (alphanumeric, matches Telebirr's charset).
	 */
	public function generateMerchantOrderId(): string
	{
		return ParameterValidator::generateMerchantOrderId();
	}

	/**
	 * Sanitize a title by removing characters Telebirr rejects.
	 */
	public function sanitizeTitle(string $title): string
	{
		return ParameterValidator::sanitizeTitle($title);
	}

	/**
	 * Format an amount to 2 decimal places.
	 *
	 * @param string|int|float $amount
	 * @throws InvalidParameterException if amount is invalid
	 */
	public function formatAmount($amount): string
	{
		return ParameterValidator::validateAmount($amount);
	}

	/**
	 * Check whether a merchant order ID matches Telebirr's required format.
	 */
	public function isValidMerchantOrderId(string $merchantOrderId): bool
	{
		return ParameterValidator::isValidMerchantOrderId($merchantOrderId);
	}

	/**
	 * Log an API request (sensitive fields redacted).
	 */
	private function logRequest(string $method, string $url, array $data): void
	{
		$this->logger->debug('Telebirr API Request', [
			'method' => $method,
			'url' => $url,
			'data' => $this->sanitizeLogData($data)
		]);
	}

	/**
	 * Log an API response (sensitive/PII fields redacted).
	 */
	private function logResponse(string $method, int $httpCode, string $response): void
	{
		$level = ($httpCode >= 200 && $httpCode < 300) ? 'info' : 'error';
		$this->logger->log($level, 'Telebirr API Response', [
			'method' => $method,
			'http_code' => $httpCode,
			'response' => $this->sanitizeResponseData($response)
		]);
	}

	/**
	 * Redact sensitive fields from a request payload before logging.
	 */
	private function sanitizeLogData(array $data): array
	{
		$sanitized = $data;

		if (isset($sanitized['appSecret'])) {
			$sanitized['appSecret'] = '[REDACTED]';
		}

		if (isset($sanitized['biz_content']) && is_array($sanitized['biz_content'])) {
			if (isset($sanitized['biz_content']['privateKey'])) {
				$sanitized['biz_content']['privateKey'] = '[REDACTED]';
			}
		}

		if (isset($sanitized['sign'])) {
			$sanitized['sign'] = substr((string) $sanitized['sign'], 0, 20) . '...';
		}

		return $sanitized;
	}

	/**
	 * Redact PII/sensitive fields from a response body before logging.
	 *
	 * Responses can carry customer PII (name, phone/msisdn) and signatures.
	 * Returns a decoded+redacted array for JSON bodies, or a length-capped
	 * string for non-JSON bodies.
	 *
	 * @return array|string
	 */
	private function sanitizeResponseData(string $response)
	{
		$decoded = json_decode($response, true);
		if (!is_array($decoded)) {
			// Not JSON: cap length so raw bodies don't bloat logs.
			return strlen($response) > 500 ? substr($response, 0, 500) . '…[truncated]' : $response;
		}

		return $this->redactSensitiveKeys($decoded);
	}

	/**
	 * Recursively redact known sensitive/PII keys from a decoded structure.
	 */
	private function redactSensitiveKeys(array $data): array
	{
		$sensitive = [
			'sign', 'msisdn', 'phone', 'phone_no', 'phonenumber', 'payer_name',
			'customer_name', 'buyer', 'openid', 'open_id', 'id_no', 'email',
		];

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = $this->redactSensitiveKeys($value);
				continue;
			}
			if (in_array(strtolower((string) $key), $sensitive, true)) {
				$data[$key] = '[REDACTED]';
			}
		}

		return $data;
	}

	/**
	 * Format an HTTP-level API error message with helpful context.
	 */
	private function formatApiError(string $operation, int $httpCode, string $responseBody): string
	{
		$message = "{$operation} API returned HTTP {$httpCode}";

		$errorData = json_decode($responseBody, true);
		if (is_array($errorData)) {
			$errorCode = $errorData['errorCode'] ?? $errorData['code'] ?? null;
			$errorMsg = $errorData['errorMsg'] ?? $errorData['message'] ?? $errorData['msg'] ?? null;
			$errorSolution = $errorData['errorSolution'] ?? null;

			if ($errorCode) {
				$message .= "\nError Code: {$errorCode}";
			}
			if ($errorMsg) {
				$message .= "\nError Message: {$errorMsg}";
			}
			if ($errorSolution) {
				$message .= "\nSolution: {$errorSolution}";
			}

			if ($errorCode === '49401024995') {
				$message .= "\n\nThis error indicates a parameter validation issue.";
				$message .= "\nCommon causes:";
				$message .= "\n- Invalid merchant order ID format (must be alphanumeric only)";
				$message .= "\n- Invalid title characters (special characters not allowed)";
				$message .= "\n- Parameter type mismatch";
			}
		} else {
			$message .= ": {$responseBody}";
		}

		return $message;
	}

	/**
	 * Format an API-level (code != success) error message with helpful context.
	 */
	private function formatApiErrorResponse(string $operation, array $result): string
	{
		$errorCode = $result['code'] ?? $result['errorCode'] ?? 'Unknown';
		$errorMsg = $result['message'] ?? $result['msg'] ?? $result['errorMsg'] ?? 'Unknown error';
		$errorSolution = $result['errorSolution'] ?? null;

		$message = "{$operation} API error (code: {$errorCode}): {$errorMsg}";

		if ($errorSolution) {
			$message .= "\nSolution: {$errorSolution}";
		}

		if ($errorCode === '49401024995') {
			$message .= "\n\nThis error indicates a parameter validation issue.";
			$message .= "\nCommon causes:";
			$message .= "\n- Invalid merchant order ID format (must be alphanumeric only, no underscores)";
			$message .= "\n- Invalid title characters (special characters like #, !, $, etc. not allowed)";
			$message .= "\n- Parameter type mismatch";
			$message .= "\n\nTip: Use ParameterValidator::validateTitle() and ParameterValidator::validateMerchantOrderId() to validate parameters before calling the API.";
		} elseif ($errorCode === '60320025') {
			$message .= "\n\nThis error typically indicates:";
			$message .= "\n- Payment platform unavailable";
			$message .= "\n- Account permissions issue";
			$message .= "\n- Environment mismatch (test vs production)";
		}

		return $message;
	}
}
