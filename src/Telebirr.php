<?php

namespace Melaku\Telebirr;

class Telebirr
{
    private $config;

    public function __construct(TelebirrConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Main public method that orchestrates the entire H5 payment flow.
     */
    public function getH5CheckoutUrl(string $title, float $amount, string $notifyUrl, string $returnUrl, string $orderId): string
    {
        // Step 1: Get the fabric token
        $tokenData = $this->getFabricToken();
        $fabricToken = $tokenData['token'];

        // Step 2: Create the pre-order to get the prepay_id
        $prepayResponse = $this->createPrepayOrder($fabricToken, $title, $amount, $notifyUrl, $returnUrl, $orderId);
        $prepayId = $prepayResponse['biz_content']['prepay_id'];

        // Step 3: Build the final rawRequest string for the H5 URL
        return $this->buildH5RawRequestUrl($prepayId);
    }

    private function getFabricToken(): array
    {
        $url = $this->config->getApiBaseUrl() . '/payment/v1/token';
        $headers = ['Content-Type: application/json', 'X-APP-Key: ' . $this->config->getFabricAppId()];
        $payload = ['appSecret' => $this->config->getAppSecret()];
        return $this->sendRequest($url, json_encode($payload), $headers);
    }

    private function createPrepayOrder(string $fabricToken, string $title, float $amount, string $notifyUrl, string $returnUrl, string $orderId): array
    {
        $url = $this->config->getApiBaseUrl() . '/payment/v1/merchant/preOrder';
        $headers = [
            'Content-Type: application/json',
            'X-APP-Key: ' . $this->config->getFabricAppId(),
            'Authorization: ' . $fabricToken,
        ];

        $bizContent = [
            'appid' => $this->config->getMerchantAppId(),
            'merch_code' => $this->config->getShortCode(),
            'merch_order_id' => $orderId,
            'notify_url' => $notifyUrl,
            'trade_type' => 'Checkout',
            'title' => $title,
            'total_amount' => number_format($amount, 2, '.', ''),
            'trans_currency' => 'ETB',
            'timeout_express' => '120m',
            'business_type' => 'BuyGoods',
            'payee_identifier' => $this->config->getShortCode(),
            'payee_identifier_type' => '04',
            'payee_type' => '5000',
            'redirect_url' => $returnUrl,
        ];

        $payload = [
            'method' => 'payment.preorder',
            'nonce_str' => bin2hex(random_bytes(16)),
            'timestamp' => (string) time(),
            'version' => '1.0',
            'biz_content' => $bizContent,
        ];

        $payload['sign'] = $this->sign($payload);
        $payload['sign_type'] = 'SHA256WithRSA';
        
        return $this->sendRequest($url, json_encode($payload), $headers);
    }

    private function buildH5RawRequestUrl(string $prepayId): string
    {
        $params = [
            'appid' => $this->config->getMerchantAppId(),
            'merch_code' => $this->config->getShortCode(),
            'nonce_str' => bin2hex(random_bytes(16)),
            'prepay_id' => $prepayId,
            'timestamp' => (string) time(),
        ];

        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'SHA256WithRSA';
        
        return $this->config->getH5CheckoutUrl() . http_build_query($params);
    }

    private function sign(array $data): string
    {
        if (isset($data['biz_content']) && is_array($data['biz_content'])) {
            $data = array_merge($data, $data['biz_content']);
            unset($data['biz_content']);
        }
        
        unset($data['sign'], $data['sign_type']);
        ksort($data);
        
        $stringToSign = '';
        foreach ($data as $key => $value) {
            if ($value !== '' && !is_null($value)) {
                $stringToSign .= "{$key}={$value}&";
            }
        }
        $stringToSign = rtrim($stringToSign, '&');

        $pkey = openssl_pkey_get_private(
            "-----BEGIN PRIVATE KEY-----\n" .
            wordwrap($this->config->getPrivateKey(), 64, "\n", true) .
            "\n-----END PRIVATE KEY-----"
        );

        if (!$pkey) throw new TelebirrException('Invalid private key provided.');

        openssl_sign($stringToSign, $signature, $pkey, OPENSSL_ALGO_SHA256);
        openssl_free_key($pkey);
        return base64_encode($signature);
    }

    private function sendRequest(string $url, string $payload, array $headers): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) throw new TelebirrException('cURL Error: ' . curl_error($ch));
        
        curl_close($ch);
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400 || (isset($decodedResponse['code']) && $decodedResponse['code'] != '0')) {
            throw new TelebirrException('Telebirr API Error: ' . ($decodedResponse['msg'] ?? 'Unknown error'), $httpCode);
        }
        return $decodedResponse;
    }
}