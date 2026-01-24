<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

use Melaku\Telebirr\Logger\LoggerInterface;
use Melaku\Telebirr\Logger\NullLogger;
use Melaku\Telebirr\Exceptions\InvalidParameterException;

/**
 * Telebirr Web Checkout client (modern API).
 *
 * This class mirrors the new `php-lib` TelebirrClient but keeps the original
 * package namespace and name (`melaku/telebirr`).
 */
class Telebirr
{
	private Config $config;
	private Signer $signer;
	private LoggerInterface $logger;

	public function __construct(Config $config, ?LoggerInterface $logger = null)
	{
		$this->config = $config;
		$this->signer = new Signer($config);
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Set logger for API request/response logging
	 * 
	 * @param LoggerInterface $logger Logger instance
	 * @return void
	 */
	public function setLogger(LoggerInterface $logger): void
	{
		$this->logger = $logger;
	}

	/**
	 * Step 1: Apply fabric token.
	 *
	 * According to Telebirr H5 C2B Web Payment Integration documentation:
	 * - Endpoint: POST /payment/v1/token
	 * - Headers: Content-Type: application/json, X-APP-Key: {fabricAppId}
	 * - Body: { "appSecret": "{appSecret}" }
	 * - Response: { "token": "Bearer xxx", "effectiveDate": "...", "expirationDate": "..." }
	 *
	 * @return array { token, effectiveDate, expirationDate, ... }
	 * @throws \RuntimeException on API errors or invalid responses
	 */
	public function applyFabricToken(): array
	{
		$url = $this->config->baseUrl . '/payment/v1/token';

		$payload = json_encode([
			'appSecret' => $this->config->appSecret,
		]);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'X-APP-Key: ' . $this->config->fabricAppId,
			],
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
		]);

		$responseBody = curl_exec($ch);
		$error        = curl_error($ch);
		$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Log request
		$this->logRequest('applyFabricToken', $url, ['appSecret' => '[REDACTED]']);

		if ($responseBody === false) {
			$errorMsg = 'Failed to call token API: ' . ($error ?: 'Unknown cURL error');
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		// Log response
		$this->logResponse('applyFabricToken', $httpCode, $responseBody);

		// Validate HTTP status code (should be 200-299 for success)
		if ($httpCode < 200 || $httpCode >= 300) {
			$errorMsg = $this->formatApiError('Token', $httpCode, $responseBody);
			$this->logger->error($errorMsg, ['http_code' => $httpCode, 'response' => $responseBody]);
			throw new \RuntimeException($errorMsg);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			$errorMsg = 'Invalid token API response (not JSON): ' . $responseBody;
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		// Check for API-level error responses
		if (isset($result['code']) && $result['code'] !== '00000' && $result['code'] !== '0') {
			$errorMsg = $this->formatApiErrorResponse('Token', $result);
			$this->logger->error($errorMsg, ['error_code' => $result['code'], 'response' => $result]);
			throw new \RuntimeException($errorMsg);
		}

		// Validate that token exists in response
		if (empty($result['token'])) {
			throw new \RuntimeException(
				'Token missing in API response. Response: ' . json_encode($result)
			);
		}

		return $result;
	}

	/**
	 * Step 2: Request create order (preOrder) – Telebirr H5 C2B requestCreateOrder.
	 *
	 * Per Telebirr H5 C2B Web Payment Integration Quick Guide (requestCreateOrder):
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/requestCreateOrder
	 *
	 * API Specification:
	 * - Endpoint: POST {baseUrl}/payment/v1/merchant/preOrder
	 * - Headers:
	 *   - Content-Type: application/json
	 *   - X-APP-Key: {fabricAppId}
	 *   - Authorization: {fabricToken} (from applyFabricToken)
	 * - Request Body:
	 *   {
	 *     "timestamp": "{timestamp}",
	 *     "nonce_str": "{nonce_str}",
	 *     "method": "payment.preorder",
	 *     "version": "1.0",
	 *     "biz_content": {
	 *       "notify_url": "{notify_url}",
	 *       "appid": "{merchantAppId}",
	 *       "merch_code": "{merchantCode}",
	 *       "merch_order_id": "{merch_order_id}",
	 *       "trade_type": "Checkout",
	 *       "title": "{title}",
	 *       "total_amount": "{amount}", // String with 2 decimal places
	 *       "trans_currency": "ETB",
	 *       "timeout_express": "120m",
	 *       "redirect_url": "{redirect_url}" // Optional
	 *     },
	 *     "sign": "{signature}",
	 *     "sign_type": "SHA256WithRSA"
	 *   }
	 * - Success Response: { "biz_content": { "prepay_id": "...", ... }, ... }
	 * - Error Response: { "code": "...", "message": "...", ... }
	 *
	 * @param string $fabricToken "Bearer xxx" from applyFabricToken()
	 * @param string $title Order title (will be automatically sanitized)
	 * @param string|int|float $amount Total amount (ETB) - will be formatted to 2 decimals
	 * @param string|null $merchOrderId Optional merchant order ID; if null, one is auto-generated
	 *
	 * @return array Full API response (contains biz_content.prepay_id on success)
	 * @throws \RuntimeException on API errors, invalid responses, or missing prepay_id
	 * @throws InvalidParameterException on parameter validation failures
	 */
	public function createOrder(string $fabricToken, string $title, $amount, ?string $merchOrderId = null): array
	{
		// Validate and sanitize parameters
		try {
			$title = ParameterValidator::validateTitle($title, true);
			$amount = ParameterValidator::validateAmount($amount);
			$merchOrderId = ParameterValidator::validateMerchantOrderId($merchOrderId, true);
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

		$payload = json_encode($reqObject);

		// Log request
		$this->logRequest('createOrder', $url, $reqObject);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'X-APP-Key: ' . $this->config->fabricAppId,
				'Authorization: ' . $fabricToken,
			],
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
		]);

		$responseBody = curl_exec($ch);
		$error        = curl_error($ch);
		$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Log response
		$this->logResponse('createOrder', $httpCode, $responseBody);

		if ($responseBody === false) {
			$errorMsg = 'Failed to call create order API: ' . ($error ?: 'Unknown cURL error');
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		// Validate HTTP status code (should be 200-299 for success)
		if ($httpCode < 200 || $httpCode >= 300) {
			$errorMsg = $this->formatApiError('Create order', $httpCode, $responseBody);
			$this->logger->error($errorMsg, ['http_code' => $httpCode, 'response' => $responseBody]);
			throw new \RuntimeException($errorMsg);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			$errorMsg = 'Invalid create order API response (not JSON): ' . $responseBody;
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		// Check for API-level error responses
		if (isset($result['code']) && $result['code'] !== '00000' && $result['code'] !== '0') {
			$errorMsg = $this->formatApiErrorResponse('Create order', $result);
			$this->logger->error($errorMsg, ['error_code' => $result['code'], 'response' => $result]);
			throw new \RuntimeException($errorMsg);
		}

		// Validate prepay_id in response (per requestCreateOrder spec)
		if (
			!isset($result['biz_content']) ||
			!is_array($result['biz_content']) ||
			empty($result['biz_content']['prepay_id'])
		) {
			throw new \RuntimeException(
				'prepay_id missing in create order response. Response: ' . json_encode($result)
			);
		}

		return $result;
	}

	/**
	 * Step 3: Generate checkout URL from prepay_id – Telebirr H5 C2B Generate_Check_Url.
	 *
	 * Per Telebirr H5 C2B Web Payment Integration Quick Guide (Generate_Check_Url):
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Generate_Check_Url
	 *
	 * This method generates the final checkout URL that users will be redirected to for payment.
	 * The URL is constructed by:
	 * 1. Building a signed query string with required parameters (appid, merch_code, nonce_str, prepay_id, timestamp, sign, sign_type)
	 * 2. Appending version=1.0&trade_type=Checkout
	 * 3. Combining with the webBaseUrl to form the complete payment gateway URL
	 *
	 * URL Format:
	 * {webBaseUrl}?appid={appid}&merch_code={merch_code}&nonce_str={nonce_str}&prepay_id={prepay_id}&timestamp={timestamp}&sign={sign}&sign_type=SHA256WithRSA&version=1.0&trade_type=Checkout
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
	 * Per Telebirr H5 C2B Web Payment Integration Quick Guide (queryOrder):
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/queryOrder
	 *
	 * This method queries the payment status of an order. You can query by either:
	 * - prepay_id: The payment order ID returned from createOrder
	 * - merch_order_id: Your merchant order ID
	 *
	 * API Specification:
	 * - Endpoint: POST {baseUrl}/payment/v1/merchant/queryOrder
	 * - Headers:
	 *   - Content-Type: application/json
	 *   - X-APP-Key: {fabricAppId}
	 *   - Authorization: {fabricToken} (from applyFabricToken)
	 * - Request Body:
	 *   {
	 *     "timestamp": "{timestamp}",
	 *     "nonce_str": "{nonce_str}",
	 *     "method": "payment.queryorder",
	 *     "version": "1.0",
	 *     "biz_content": {
	 *       "appid": "{merchantAppId}",
	 *       "merch_code": "{merchantCode}",
	 *       "prepay_id": "{prepay_id}", // Optional if merch_order_id is provided
	 *       "merch_order_id": "{merch_order_id}" // Optional if prepay_id is provided
	 *     },
	 *     "sign": "{signature}",
	 *     "sign_type": "SHA256WithRSA"
	 *   }
	 * - Success Response: { "biz_content": { "trade_status": "...", "payment_order_id": "...", ... }, ... }
	 * - Error Response: { "code": "...", "message": "...", ... }
	 *
	 * @param string $fabricToken "Bearer xxx" from applyFabricToken()
	 * @param string|null $prepayId Optional: prepay_id from createOrder response
	 * @param string|null $merchOrderId Optional: merchant order ID (at least one must be provided)
	 *
	 * @return array Full API response (contains biz_content with order status on success)
	 * @throws \RuntimeException on API errors, invalid responses, or if both prepayId and merchOrderId are null
	 * @throws InvalidParameterException on parameter validation failures
	 */
	public function queryOrder(string $fabricToken, ?string $prepayId = null, ?string $merchOrderId = null): array
	{
		if (empty($prepayId) && empty($merchOrderId)) {
			throw new \InvalidArgumentException('Either prepayId or merchOrderId must be provided');
		}

		// Validate merchant order ID if provided
		if (!empty($merchOrderId)) {
			try {
				$merchOrderId = ParameterValidator::validateMerchantOrderId($merchOrderId, true);
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

		$payload = json_encode($reqObject);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'X-APP-Key: ' . $this->config->fabricAppId,
				'Authorization: ' . $fabricToken,
			],
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
		]);

		$responseBody = curl_exec($ch);
		$error        = curl_error($ch);
		$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Log response
		$this->logResponse('queryOrder', $httpCode, $responseBody);

		if ($responseBody === false) {
			$errorMsg = 'Failed to call query order API: ' . ($error ?: 'Unknown cURL error');
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		// Validate HTTP status code (should be 200-299 for success)
		if ($httpCode < 200 || $httpCode >= 300) {
			$errorMsg = $this->formatApiError('Query order', $httpCode, $responseBody);
			$this->logger->error($errorMsg, ['http_code' => $httpCode, 'response' => $responseBody]);
			throw new \RuntimeException($errorMsg);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			$errorMsg = 'Invalid query order API response (not JSON): ' . $responseBody;
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		// Check for API-level error responses
		if (isset($result['code']) && $result['code'] !== '00000' && $result['code'] !== '0') {
			$errorMsg = $this->formatApiErrorResponse('Query order', $result);
			$this->logger->error($errorMsg, ['error_code' => $result['code'], 'response' => $result]);
			throw new \RuntimeException($errorMsg);
		}

		return $result;
	}

	/**
	 * Refund order – Telebirr H5 C2B RefundOrder.
	 *
	 * Per Telebirr H5 C2B Web Payment Integration Quick Guide (RefundOrder):
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/RefundOrder
	 *
	 * This method initiates a refund for a completed payment. You can refund by either:
	 * - payment_order_id: The payment order ID from Telebirr
	 * - merch_order_id: Your merchant order ID
	 *
	 * API Specification:
	 * - Endpoint: POST {baseUrl}/payment/v1/merchant/refund
	 * - Headers:
	 *   - Content-Type: application/json
	 *   - X-APP-Key: {fabricAppId}
	 *   - Authorization: {fabricToken} (from applyFabricToken)
	 * - Request Body:
	 *   {
	 *     "timestamp": "{timestamp}",
	 *     "nonce_str": "{nonce_str}",
	 *     "method": "payment.refund",
	 *     "version": "1.0",
	 *     "biz_content": {
	 *       "appid": "{merchantAppId}",
	 *       "merch_code": "{merchantCode}",
	 *       "refund_amount": "{refund_amount}", // String with 2 decimal places
	 *       "refund_request_no": "{refund_request_no}", // Required: refund request number
	 *       "payment_order_id": "{payment_order_id}", // Optional if merch_order_id is provided
	 *       "merch_order_id": "{merch_order_id}", // Optional if payment_order_id is provided
	 *       "refund_reason": "{refund_reason}", // Optional
	 *       "refund_order_id": "{refund_order_id}" // Optional
	 *     },
	 *     "sign": "{signature}",
	 *     "sign_type": "SHA256WithRSA"
	 *   }
	 * - Success Response: { "biz_content": { "refund_order_id": "...", "refund_status": "...", ... }, ... }
	 * - Error Response: { "code": "...", "message": "...", ... }
	 *
	 * @param string      $fabricToken  "Bearer xxx" from applyFabricToken()
	 * @param string $fabricToken "Bearer xxx" from applyFabricToken()
	 * @param string|int|float $refundAmount Refund amount (ETB) - will be formatted to 2 decimals
	 * @param string|null $paymentOrderId Optional: payment_order_id from Telebirr
	 * @param string|null $merchOrderId Optional: merchant order ID (at least one must be provided)
	 * @param string|null $refundReason Optional: reason for refund
	 * @param string|null $refundOrderId Optional: refund_request_no (required by API, auto-generated if null)
	 *
	 * @return array Full API response (contains biz_content with refund status on success)
	 * @throws \RuntimeException on API errors, invalid responses, or if both paymentOrderId and merchOrderId are null
	 * @throws InvalidParameterException on parameter validation failures
	 */
	public function refundOrder(string $fabricToken, $refundAmount, ?string $paymentOrderId = null, ?string $merchOrderId = null, ?string $refundReason = null, ?string $refundOrderId = null): array
	{
		if (empty($paymentOrderId) && empty($merchOrderId)) {
			throw new \InvalidArgumentException('Either paymentOrderId or merchOrderId must be provided');
		}

		// Validate parameters
		try {
			$refundAmount = ParameterValidator::validateAmount($refundAmount);
			if (!empty($merchOrderId)) {
				$merchOrderId = ParameterValidator::validateMerchantOrderId($merchOrderId, true);
			}
			if (!empty($refundOrderId)) {
				$refundOrderId = ParameterValidator::validateMerchantOrderId($refundOrderId, true);
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

		// Endpoint path per official RefundOrder documentation
		$url = $this->config->baseUrl . '/payment/v1/merchant/refund';

		$payload = json_encode($reqObject);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'X-APP-Key: ' . $this->config->fabricAppId,
				'Authorization: ' . $fabricToken,
			],
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
		]);

		// Log request
		$this->logRequest('refundOrder', $url, $reqObject);

		$responseBody = curl_exec($ch);
		$error        = curl_error($ch);
		$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// Log response
		$this->logResponse('refundOrder', $httpCode, $responseBody);

		if ($responseBody === false) {
			$errorMsg = 'Failed to call refund order API: ' . ($error ?: 'Unknown cURL error');
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		// Validate HTTP status code (should be 200-299 for success)
		if ($httpCode < 200 || $httpCode >= 300) {
			$errorDetails = '';
			if ($httpCode === 404) {
				$errorDetails = "\n\n⚠️ 404 Error - Endpoint Not Found\n";
				$errorDetails .= "The refund endpoint might not be available for your account.\n\n";
				$errorDetails .= "Current endpoint being called: " . $url . "\n\n";
				$errorDetails .= "Please verify:\n";
				$errorDetails .= "1. Check the official RefundOrder documentation:\n";
				$errorDetails .= "   https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/RefundOrder\n\n";
				$errorDetails .= "2. Verify that the RefundOrder API is enabled for your account\n\n";
				$errorDetails .= "3. Check that you're using the correct base URL:\n";
				$errorDetails .= "   - Development: https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway\n";
				$errorDetails .= "   - Production: https://telebirrappcube.ethiomobilemoney.et:38443/apiaccess/payment/gateway\n\n";
				$errorDetails .= "4. Contact Telebirr support if refunds are not available for your account.";
			}
			throw new \RuntimeException(
				'Refund order API returned HTTP ' . $httpCode . ': ' . $responseBody . $errorDetails
			);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			$errorMsg = 'Invalid refund order API response (not JSON): ' . $responseBody;
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		// Check for API-level error responses
		if (isset($result['code']) && $result['code'] !== '00000' && $result['code'] !== '0') {
			$errorMsg = $this->formatApiErrorResponse('Refund order', $result);

			// Add specific context for refund errors
			$errorCode = $result['code'] ?? $result['errorCode'] ?? 'Unknown';
			$errorMessage = $result['message'] ?? $result['msg'] ?? $result['errorMsg'] ?? 'Unknown error';

			if ($errorCode === '60320025' || strpos($errorMessage, 'failed to call the payment platform') !== false) {
				$errorMsg .= "\n\n⚠️ This error typically indicates:\n";
				$errorMsg .= "1. You may be using a development/sandbox environment where refunds are not enabled\n";
				$errorMsg .= "2. Your account may not have refund permissions enabled\n";
				$errorMsg .= "3. The original payment may not be eligible for refund (e.g., not completed, too old, etc.)\n";
				$errorMsg .= "4. You may need to use the production environment for refunds\n";
				$errorMsg .= "\nPlease verify:\n";
				$errorMsg .= "- You're using the correct base URL (production vs development)\n";
				$errorMsg .= "- Your account has refund capabilities enabled\n";
				$errorMsg .= "- The original payment was successfully completed\n";
				$errorMsg .= "- Contact Telebirr support if refunds are required for your account";
			}

			$this->logger->error($errorMsg, ['error_code' => $errorCode, 'response' => $result]);
			throw new \RuntimeException($errorMsg);
		}

		return $result;
	}

	/**
	 * High-level helper: applyFabricToken + createOrder + buildCheckoutUrl.
	 *
	 * This method performs all three steps of the Telebirr H5 C2B Web Payment Integration:
	 * 1. Apply fabric token (authentication)
	 * 2. Create order (requestCreateOrder) - gets prepay_id
	 * 3. Generate checkout URL (Generate_Check_Url)
	 *
	 * After calling this method, redirect the user to the returned URL. The user will:
	 * - See the Telebirr payment page (CheckOut step)
	 * - Complete or cancel the payment
	 * - Be redirected back to your redirectUrl (if configured) with payment status
	 * - Trigger a server-to-server notification to your notifyUrl
	 *
	 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/%20CheckOut
	 *
	 * @param string $title Order title (will be automatically sanitized)
	 * @param string|int|float $amount Total amount (ETB) - will be formatted to 2 decimals
	 * @param string|null $merchOrderId Optional merchant order ID; if null, one is generated
	 *
	 * @return string Checkout URL to redirect the user to (user will complete payment on Telebirr page)
	 * @throws \RuntimeException on API errors
	 * @throws InvalidParameterException on parameter validation failures
	 */
	public function createCheckoutUrl(string $title, $amount, ?string $merchOrderId = null): string
	{
		$tokenInfo   = $this->applyFabricToken();
		$fabricToken = $tokenInfo['token'] ?? null;
		if (!$fabricToken) {
			$errorMsg = 'Fabric token missing in token response: ' . json_encode($tokenInfo);
			$this->logger->error($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		$order = $this->createOrder($fabricToken, $title, $amount, $merchOrderId);

		return $this->buildCheckoutUrl($order['biz_content']['prepay_id']);
	}

	/**
	 * Internal: build preOrder request body per requestCreateOrder spec.
	 *
	 * Builds the complete request object according to Telebirr H5 C2B Web Payment Integration
	 * Quick Guide (requestCreateOrder). All fields are properly formatted and signed.
	 *
	 * @param string $title Order title (already validated and sanitized)
	 * @param string $amount Total amount (already validated and formatted)
	 * @param string $merchOrderId Merchant order ID (already validated and sanitized)
	 * @return array Complete signed request object ready for JSON encoding
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

		// Add redirect_url if configured (optional - for user redirect after payment)
		if ($this->config->redirectUrl !== null && !empty($this->config->redirectUrl)) {
			$biz['redirect_url'] = $this->config->redirectUrl;
		}

		$req['biz_content'] = $biz;

		$req['sign']      = $this->signer->signRequestObject($req);
		$req['sign_type'] = 'SHA256WithRSA';

		return $req;
	}

	/**
	 * Internal: build raw request string for the web checkout URL.
	 *
	 * Builds the signed query string according to Telebirr H5 C2B Web Payment Integration
	 * Quick Guide (Generate_Check_Url). This creates the parameter string that will be
	 * appended to the webBaseUrl to form the complete checkout URL.
	 *
	 * Parameters included (in alphabetical order for signing):
	 * - appid: Merchant application ID
	 * - merch_code: Merchant code
	 * - nonce_str: Random nonce string (32 characters)
	 * - prepay_id: Prepayment ID from createOrder response
	 * - timestamp: Unix timestamp
	 *
	 * The parameters are signed using RSA-PSS SHA256, and the signature is base64-encoded.
	 * The final query string includes: appid, merch_code, nonce_str, prepay_id, timestamp, sign, sign_type
	 *
	 * @param string $prepayId The prepay_id from createOrder() response
	 * @return string URL-encoded query string (without the base URL or ? prefix)
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
	 *
	 * Builds the complete request object according to Telebirr H5 C2B Web Payment Integration
	 * Quick Guide (queryOrder). All fields are properly formatted and signed.
	 *
	 * @param string|null $prepayId     Optional prepay_id
	 * @param string|null $merchOrderId Optional merchant order ID
	 * @return array Complete signed request object ready for JSON encoding
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

		// Add prepay_id if provided
		if (!empty($prepayId)) {
			$biz['prepay_id'] = $prepayId;
		}

		// Add merch_order_id if provided
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
	 *
	 * Builds the complete request object according to Telebirr H5 C2B Web Payment Integration
	 * Quick Guide (RefundOrder). All fields are properly formatted and signed.
	 *
	 * @param string $refundAmount Refund amount (already validated and formatted)
	 * @param string|null $paymentOrderId Optional payment_order_id
	 * @param string|null $merchOrderId Optional merchant order ID (already validated if provided)
	 * @param string|null $refundReason Optional refund reason
	 * @param string|null $refundOrderId Refund request no (already validated if provided)
	 * @return array Complete signed request object ready for JSON encoding
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
			'refund_request_no' => $refundRequestNo, // Required parameter
		];

		// Add payment_order_id if provided
		if (!empty($paymentOrderId)) {
			$biz['payment_order_id'] = $paymentOrderId;
		}

		// Add merch_order_id if provided
		if (!empty($merchOrderId)) {
			$biz['merch_order_id'] = $merchOrderId;
		}

		// Add refund_reason if provided
		if (!empty($refundReason)) {
			$biz['refund_reason'] = $refundReason;
		}

		// Add refund_order_id if provided (optional field, separate from refund_request_no)
		if ($refundOrderId !== null && $refundOrderId !== '') {
			$biz['refund_order_id'] = $refundOrderId;
		}

		$req['biz_content'] = $biz;

		$req['sign']      = $this->signer->signRequestObject($req);
		$req['sign_type'] = 'SHA256WithRSA';

		return $req;
	}

	private function createMerchantOrderId(): string
	{
		return (string) (int) (microtime(true) * 1000);
	}

	/**
	 * Generate a valid merchant order ID
	 * 
	 * Public helper method that generates a merchant order ID matching Telebirr requirements
	 * (alphanumeric only, no underscores or special characters)
	 * 
	 * @return string Generated merchant order ID
	 */
	public function generateMerchantOrderId(): string
	{
		return ParameterValidator::generateMerchantOrderId();
	}

	/**
	 * Sanitize title for Telebirr API
	 * 
	 * Removes invalid characters from title per Telebirr requirements
	 * 
	 * @param string $title Title to sanitize
	 * @return string Sanitized title
	 */
	public function sanitizeTitle(string $title): string
	{
		return ParameterValidator::sanitizeTitle($title);
	}

	/**
	 * Format amount to 2 decimal places
	 * 
	 * @param string|int|float $amount Amount to format
	 * @return string Formatted amount with 2 decimal places
	 * @throws InvalidParameterException if amount is invalid
	 */
	public function formatAmount($amount): string
	{
		return ParameterValidator::validateAmount($amount);
	}

	/**
	 * Validate merchant order ID format
	 * 
	 * @param string $merchantOrderId Merchant order ID to check
	 * @return bool True if valid format
	 */
	public function isValidMerchantOrderId(string $merchantOrderId): bool
	{
		return ParameterValidator::isValidMerchantOrderId($merchantOrderId);
	}

	/**
	 * Log API request
	 * 
	 * @param string $method Method name (e.g., 'createOrder')
	 * @param string $url Request URL
	 * @param array $data Request data (will be sanitized for logging)
	 * @return void
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
	 * Log API response
	 * 
	 * @param string $method Method name
	 * @param int $httpCode HTTP status code
	 * @param string $response Response body
	 * @return void
	 */
	private function logResponse(string $method, int $httpCode, string $response): void
	{
		$level = ($httpCode >= 200 && $httpCode < 300) ? 'info' : 'error';
		$this->logger->$level('Telebirr API Response', [
			'method' => $method,
			'http_code' => $httpCode,
			'response' => $response
		]);
	}

	/**
	 * Sanitize data for logging (remove sensitive information)
	 * 
	 * @param array $data Data to sanitize
	 * @return array Sanitized data
	 */
	private function sanitizeLogData(array $data): array
	{
		$sanitized = $data;

		// Remove or mask sensitive fields
		if (isset($sanitized['biz_content'])) {
			$biz = $sanitized['biz_content'];
			// Don't log full private key if present
			if (isset($biz['privateKey'])) {
				$sanitized['biz_content']['privateKey'] = '[REDACTED]';
			}
		}

		// Don't log full signature
		if (isset($sanitized['sign'])) {
			$sanitized['sign'] = substr($sanitized['sign'], 0, 20) . '...';
		}

		return $sanitized;
	}

	/**
	 * Format API error message with helpful context
	 * 
	 * @param string $operation Operation name (e.g., 'Create order')
	 * @param int $httpCode HTTP status code
	 * @param string $responseBody Response body
	 * @return string Formatted error message
	 */
	private function formatApiError(string $operation, int $httpCode, string $responseBody): string
	{
		$message = "{$operation} API returned HTTP {$httpCode}";

		// Try to parse error response
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

			// Add specific help for common error codes
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
	 * Format API error response with helpful context
	 * 
	 * @param string $operation Operation name
	 * @param array $result Error response array
	 * @return string Formatted error message
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

		// Add specific help for common error codes
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
