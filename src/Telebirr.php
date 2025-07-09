<?php

namespace Melaku\Telebirr;

class Telebirr
{
    private $config;

    public function __construct(TelebirrConfig $config)
    {
        $this->config = $config;
    }

    // Main public method - we will test its components first
    public function getH5CheckoutUrl(string $title, float $amount, string $notifyUrl, string $returnUrl): string
    {
        $tokenData = $this->getFabricToken();
        $fabricToken = $tokenData['token'];
        $prepayResponse = $this->createPrepayOrder($fabricToken, $title, $amount, $notifyUrl, $returnUrl);
        $prepayId = $prepayResponse['biz_content']['prepay_id'];
        return $this->buildH5RawRequestUrl($prepayId);
    }

    // Step 1: Get Fabric Token (Public for testing)
    public function getFabricToken(): array
    {
        $url = $this->config->getApiBaseUrl() . '/payment/v1/token';
        $headers = ['Content-Type: application/json', 'X-APP-Key: ' . $this->config->getFabricAppId()];
        $payload = ['appSecret' => $this->config->getAppSecret()];
        return $this->sendRequest($url, json_encode($payload), $headers);
    }

    // Step 2: Create Pre-Order (Public for testing)
    // The payload now exactly matches the working example's log, with the critical fix.
    public function createPrepayOrder(string $fabricToken, string $title, float $amount, string $notifyUrl, string $returnUrl): array
    {
        $url = $this->config->getApiBaseUrl() . '/payment/v1/merchant/preOrder';
        $headers = [
            'Content-Type: application/json',
            'X-APP-Key: ' . $this->config->getFabricAppId(),
            'Authorization: ' . $fabricToken,
        ];

        // This payload is now a 1:1 match with your log, with the total_amount fix
        $bizContent = [
            'notify_url' => $notifyUrl,
            'business_type' => 'BuyGoods',
            'trade_type' => 'InApp',
            'appid' => $this->config->getMerchantAppId(),
            'merch_code' => $this->config->getShortCode(),
            'merch_order_id' => '1752073443', // Hardcoded from your log
            'title' => $title,
            // --- THIS IS THE CRITICAL FIX ---
            'total_amount' => number_format($amount, 2, '.', ''), // Sending as a STRING "1.00"
            'trans_currency' => 'ETB',
            'timeout_express' => '120m',
            'payee_identifier' => '220311',
            'payee_identifier_type' => '04',
            'payee_type' => '5000',
            'redirect_url' => $returnUrl,
            'callback_info' => 'From web',
        ];

        $payload = [
            'nonce_str' => 'fcab0d2949e64a69a212aa83eab6ee1d', // Hardcoded from your log
            'method' => 'payment.preorder',
            'timestamp' => '1752073443', // Hardcoded from your log
            'version' => '1.0',
            'biz_content' => $bizContent,
        ];

        $payload['sign'] = $this->sign($payload);
        $payload['sign_type'] = 'SHA256WithRSA';
        
        return $this->sendRequest($url, json_encode($payload), $headers);
    }

    private function buildH5RawRequestUrl(string $prepayId): string { /* ... */ return ""; }

    private function sign(array $data): string {
        if (isset($data['biz_content']) && is_array($data['biz_content'])) {
            $data = array_merge($data, $data['biz_content']);
            unset($data['biz_content']);
        }
        unset($data['sign'], $data['sign_type']);
        $stringToBuild = '';
        foreach ($data as $key => $value) {
            if ($value !== '' && !is_null($value)) {
                $stringToBuild .= "{$key}={$value}&";
            }
        }
        $stringToBuild = rtrim($stringToBuild, '&');
        $sortedArray = explode('&', $stringToBuild);
        sort($sortedArray);
        $stringToSign = implode('&', $sortedArray);
        $pkey = openssl_pkey_get_private("-----BEGIN PRIVATE KEY-----\n" . wordwrap($this->config->getPrivateKey(), 64, "\n", true) . "\n-----END PRIVATE KEY-----");
        if (!$pkey) throw new TelebirrException('Invalid private key provided.');
        openssl_sign($stringToSign, $signature, $pkey, OPENSSL_ALGO_SHA256);
        openssl_free_key($pkey);
        return base64_encode($signature);
    }

    private function sendRequest(string $url, string $payload, array $headers): array {
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
        if ($decodedResponse === null && !empty($response)) {
             throw new TelebirrException('API did not return valid JSON. Response: ' . $response, $httpCode);
        }
        if ($httpCode >= 400 || (isset($decodedResponse['code']) && $decodedResponse['code'] != '0' && $decodedResponse['code'] != '200')) {
            throw new TelebirrException('Telebirr API Error: ' . ($decodedResponse['msg'] ?? 'Unknown error'), $httpCode);
        }
        return $decodedResponse;
    }
}