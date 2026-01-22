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
	 * @return array { token, effectiveDate, expirationDate, ... }
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
		curl_close($ch);

		if ($responseBody === false) {
			throw new \RuntimeException('Failed to call token API: ' . $error);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			throw new \RuntimeException('Invalid token API response: ' . $responseBody);
		}

		return $result;
	}

	/**
	 * Step 2: Create order and return full Telebirr response.
	 *
	 * @param string $fabricToken "Bearer xxx"
	 * @param string $title       Order title
	 * @param string|int|float $amount  Total amount
	 *
	 * @return array Full API response (should contain biz_content.prepay_id on success)
	 */
	public function createOrder(string $fabricToken, string $title, $amount): array
	{
		$reqObject = $this->buildPreOrderRequest($title, $amount);

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
		curl_close($ch);

		if ($responseBody === false) {
			throw new \RuntimeException('Failed to call create order API: ' . $error);
		}

		$result = json_decode($responseBody, true);
		if (!is_array($result)) {
			throw new \RuntimeException('Invalid create order API response: ' . $responseBody);
		}

		return $result;
	}

	/**
	 * Step 3: Build checkout URL from prepay_id.
	 */
	public function buildCheckoutUrl(string $prepayId): string
	{
		$rawRequest = $this->buildRawCheckoutRequest($prepayId);

		return $this->config->webBaseUrl . $rawRequest . '&version=1.0&trade_type=Checkout';
	}

	/**
	 * High-level helper: do all steps and return final checkout URL.
	 */
	public function createCheckoutUrl(string $title, $amount): string
	{
		$tokenInfo   = $this->applyFabricToken();
		$fabricToken = $tokenInfo['token'] ?? null;
		if (!$fabricToken) {
			throw new \RuntimeException('Fabric token missing in token response: ' . json_encode($tokenInfo));
		}

		$order = $this->createOrder($fabricToken, $title, $amount);

		if (
			!isset($order['biz_content']) ||
			!is_array($order['biz_content']) ||
			!isset($order['biz_content']['prepay_id'])
		) {
			throw new \RuntimeException(
				'prepay_id missing in create order response: ' . json_encode($order)
			);
		}

		return $this->buildCheckoutUrl($order['biz_content']['prepay_id']);
	}

	/**
	 * Internal: build preOrder request body exactly as Telebirr expects.
	 */
	private function buildPreOrderRequest(string $title, $amount): array
	{
		// API expects total_amount as string
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
			'notify_url'     => $this->config->notifyUrl,
			'appid'          => $this->config->merchantAppId,
			'merch_code'     => $this->config->merchantCode,
			'merch_order_id' => $this->createMerchantOrderId(),
			'trade_type'     => 'Checkout',
			'title'          => $title,
			'total_amount'   => $amountStr,
			'trans_currency' => 'ETB',
			'timeout_express' => '120m',
		];
		
		// Add redirect_url if configured (optional - for user redirect after payment)
		if ($this->config->redirectUrl !== null) {
			$biz['redirect_url'] = $this->config->redirectUrl;
		}

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

	private function createMerchantOrderId(): string
	{
		return (string) (int) (microtime(true) * 1000);
	}
}
