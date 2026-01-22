<?php

namespace Melaku\Telebirr;

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

	public function __construct(Config $config)
	{
		$this->config = $config;
		$this->signer = new Signer($config);
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

		if ($responseBody === false) {
			throw new \RuntimeException('Failed to call token API: ' . ($error ?: 'Unknown cURL error'));
		}

		// Validate HTTP status code (should be 200-299 for success)
		if ($httpCode < 200 || $httpCode >= 300) {
			throw new \RuntimeException(
				'Token API returned HTTP ' . $httpCode . ': ' . $responseBody
			);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			throw new \RuntimeException('Invalid token API response (not JSON): ' . $responseBody);
		}

		// Check for API-level error responses
		if (isset($result['code']) && $result['code'] !== '00000' && $result['code'] !== '0') {
			$errorMsg = $result['message'] ?? $result['msg'] ?? 'Unknown error';
			throw new \RuntimeException(
				'Token API error (code: ' . $result['code'] . '): ' . $errorMsg
			);
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
	 * @param string      $fabricToken  "Bearer xxx" from applyFabricToken()
	 * @param string      $title        Order title
	 * @param string|int|float $amount  Total amount (ETB) - will be formatted to 2 decimals
	 * @param string|null $merchOrderId Optional merchant order ID; if null, one is auto-generated
	 *
	 * @return array Full API response (contains biz_content.prepay_id on success)
	 * @throws \RuntimeException on API errors, invalid responses, or missing prepay_id
	 */
	public function createOrder(string $fabricToken, string $title, $amount, ?string $merchOrderId = null): array
	{
		$reqObject = $this->buildPreOrderRequest($title, $amount, $merchOrderId);

		$url = $this->config->baseUrl . '/payment/v1/merchant/preOrder';

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

		if ($responseBody === false) {
			throw new \RuntimeException('Failed to call create order API: ' . ($error ?: 'Unknown cURL error'));
		}

		// Validate HTTP status code (should be 200-299 for success)
		if ($httpCode < 200 || $httpCode >= 300) {
			throw new \RuntimeException(
				'Create order API returned HTTP ' . $httpCode . ': ' . $responseBody
			);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			throw new \RuntimeException('Invalid create order API response (not JSON): ' . $responseBody);
		}

		// Check for API-level error responses
		if (isset($result['code']) && $result['code'] !== '00000' && $result['code'] !== '0') {
			$errorMsg = $result['message'] ?? $result['msg'] ?? 'Unknown error';
			throw new \RuntimeException(
				'Create order API error (code: ' . $result['code'] . '): ' . $errorMsg
			);
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
	 * High-level helper: applyFabricToken + createOrder + buildCheckoutUrl.
	 *
	 * @param string      $title        Order title
	 * @param string|int|float $amount  Total amount (ETB)
	 * @param string|null $merchOrderId Optional merchant order ID; if null, one is generated
	 *
	 * @return string Checkout URL to redirect the user to
	 */
	public function createCheckoutUrl(string $title, $amount, ?string $merchOrderId = null): string
	{
		$tokenInfo   = $this->applyFabricToken();
		$fabricToken = $tokenInfo['token'] ?? null;
		if (!$fabricToken) {
			throw new \RuntimeException('Fabric token missing in token response: ' . json_encode($tokenInfo));
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
	 * @param string      $title        Order title
	 * @param string|int|float $amount  Total amount (will be formatted to string with 2 decimals)
	 * @param string|null $merchOrderId Optional merchant order ID; if null, auto-generated
	 * @return array Complete signed request object ready for JSON encoding
	 */
	private function buildPreOrderRequest(string $title, $amount, ?string $merchOrderId = null): array
	{
		// API expects total_amount as string with 2 decimals
		$amountStr = is_numeric($amount)
			? number_format((float) $amount, 2, '.', '')
			: (string) $amount;

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
			'merch_order_id'  => $merchOrderId !== null && $merchOrderId !== '' ? $merchOrderId : $this->createMerchantOrderId(),
			'trade_type'      => 'Checkout',
			'title'           => $title,
			'total_amount'    => $amountStr,
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

	private function createMerchantOrderId(): string
	{
		return (string) (int) (microtime(true) * 1000);
	}
}
