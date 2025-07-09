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
            'merch_order_id' => (string)time(),
            'title' => $title,
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
            'nonce_str' => $this->generateNonceStr(),
            'method' => 'payment.preorder',
            'timestamp' => (string)time(),
            'version' => '1.0',
            'biz_content' => $bizContent,
        ];

        $payload['sign'] = $this->sign($payload);
        $payload['sign_type'] = 'SHA256WithRSA';
        echo "\n📦 Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n";

        return $this->sendRequest($url, json_encode($payload), $headers);
    }

    private function buildH5RawRequestUrl(string $prepayId): string
    {
        $params = [
            "appid" => $this->config->getMerchantAppId(),
            "merch_code" => $this->config->getShortCode(),
            "nonce_str" => $this->generateNonceStr(),
            "prepay_id" => $prepayId,
            "timestamp" => time(),
            "sign_type" => "SHA256WithRSA",
        ];

        $params['sign'] = $this->sign($params);

        return $this->config->getH5CheckoutUrl() . http_build_query($params);
    }

    private function sign(array $request): string
    {
        $flat = [];

        // Merge biz_content into root
        foreach ($request as $key => $value) {
            if (in_array($key, ['sign', 'sign_type', 'header', 'refund_info', 'openType', 'raw_request'])) {
                continue;
            }

            if ($key === 'biz_content' && is_array($value)) {
                foreach ($value as $k => $v) {
                    $flat[$k] = ($k === 'total_amount') ? number_format((float)$v, 2, '.', '') : $v;
                }
            } else {
                $flat[$key] = $value;
            }
        }

        // Sort the flat map
        ksort($flat);

        // Build the query string
        $stringToSign = urldecode(http_build_query($flat));

        echo "\n🔐 String to Sign:\n" . $stringToSign . "\n";

        return $this->signWithRSA($stringToSign);
    }

    private function signWithRSA(string $data): string
    {
        $privateKey = $this->config->getPrivateKey();
        $privateKey = $this->trimPrivateKey($privateKey);

        $pkey = openssl_pkey_get_private($privateKey);
        if (!$pkey) {
            throw new TelebirrException("Failed to load private key.");
        }

        $signature = '';
        $ok = openssl_sign($data, $signature, $pkey, OPENSSL_ALGO_SHA256);
        openssl_free_key($pkey);

        if (!$ok) {
            throw new TelebirrException("Failed to generate signature using OpenSSL.");
        }

        return base64_encode($signature);
    }

    private function trimPrivateKey(string $key): string
    {
        // same as in tool.php from Telebirr sample
        if (strpos($key, 'BEGIN PRIVATE KEY') === false) {
            return "-----BEGIN PRIVATE KEY-----\n" .
                wordwrap($key, 64, "\n", true) .
                "\n-----END PRIVATE KEY-----";
        }
        return $key;
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

        echo "\n🧪 Raw Response:\n$response\n";

        if (curl_errno($ch)) throw new TelebirrException('cURL Error: ' . curl_error($ch));

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

    private function generateNonceStr(): string
    {
        return bin2hex(random_bytes(16));
    }
}
