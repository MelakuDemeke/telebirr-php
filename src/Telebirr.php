<?php

namespace Melaku\Telebirr;

use Melaku\Telebirr\Utils\Tool;
use InvalidArgumentException;

/**
 * Main class for interacting with the Telebirr API.
 *
 * This class provides methods to handle Telebirr payment gateway integration,
 * including creating orders and applying for fabric tokens.
 */
class Telebirr
{
    /** @var string The base URL for the Telebirr API. */
    private $baseUrl;

    /** @var string The Fabric App ID provided by Telebirr. */
    private $fabricAppId;

    /** @var string The App Secret provided by Telebirr. */
    private $appSecret;

    /** @var string The Merchant App ID provided by Telebirr. */
    private $merchantAppId;

    /** @var string The merchant's short code. */
    private $merchantCode;

    /** @var string The merchant's private key for signing requests. */
    private $privateKey;

    /**
     * Telebirr constructor.
     *
     * @param array $config The configuration array.
     * @throws InvalidArgumentException If a required configuration key is missing.
     */
    public function __construct(array $config)
    {
        $requiredKeys = ['baseUrl', 'fabricAppId', 'appSecret', 'merchantAppId', 'merchantCode', 'privateKey'];
        foreach ($requiredKeys as $key) {
            if (!isset($config[$key]) || empty($config[$key])) {
                throw new InvalidArgumentException("Configuration key '{$key}' is required.");
            }
        }

        $this->baseUrl = $config['baseUrl'];
        $this->fabricAppId = $config['fabricAppId'];
        $this->appSecret = $config['appSecret'];
        $this->merchantAppId = $config['merchantAppId'];
        $this->merchantCode = $config['merchantCode'];

        // The private key handler automatically formats the key.
        $this->privateKey = $this->formatPrivateKey($config['privateKey']);
    }

    /**
     * Formats the private key by wrapping it if necessary.
     *
     * This allows users to provide either the raw key string or the full PEM formatted key.
     *
     * @param string $privateKey The private key string.
     * @return string The PEM-formatted private key.
     */
    private function formatPrivateKey($privateKey)
    {
        // Trim whitespace from the key
        $trimmedKey = trim($privateKey);

        // Check if the key is already in the correct PEM format
        if (strpos($trimmedKey, '-----BEGIN PRIVATE KEY-----') === 0) {
            return $trimmedKey;
        }

        // If not, wrap the raw key in the required PEM headers
        return "-----BEGIN PRIVATE KEY-----\n" . $trimmedKey . "\n-----END PRIVATE KEY-----";
    }


    /**
     * Get a new instance of the Tool class.
     *
     * @return Tool
     */
    private function getTool()
    {
        return new Tool($this->privateKey);
    }


    /**
     * Applies for a fabric token from the Telebirr API.
     *
     * @return array The response from the API, decoded as an associative array.
     */
    public function applyFabricToken()
    {
        $tool = $this->getTool();
        $url = $this->baseUrl . '/payment/v1/token';
        $headers = [
            'Content-Type: application/json',
            'X-APP-Key: ' . $this->fabricAppId,
        ];
        $payload = [
            'appSecret' => $this->appSecret,
        ];

        $response = $tool->sendRequest($url, json_encode($payload), $headers);
        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON response from token API', 'raw_response' => $response];
        }

        return $decodedResponse;
    }

    /**
     * Creates a new order.
     *
     * @param array $orderData The data for the order. This now accepts all biz_content fields.
     * @return string|array The raw request string for redirection on success, or an error array on failure.
     */
    public function createOrder(array $orderData)
    {
        $tool = $this->getTool();
        $tokenData = $this->applyFabricToken();
        if (!isset($tokenData['token']) || empty($tokenData['token'])) {
            return $tokenData;
        }

        $fabricToken = $tokenData['token'];
        $url = $this->baseUrl . '/payment/v1/merchant/preOrder';
        $headers = [
            'Content-Type: application/json',
            'X-APP-Key: ' . $this->fabricAppId,
            'Authorization: ' . $fabricToken,
        ];

        $requestData = $this->createRequestObject($orderData, $tool);
        $response = $tool->sendRequest($url, $requestData, $headers);
        $decodedResponse = json_decode($response);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decodedResponse->biz_content->prepay_id)) {
            return ['error' => 'Failed to get prepay_id from preOrder response.', 'raw_response' => $response];
        }

        $prepayId = $decodedResponse->biz_content->prepay_id;

        return $this->createRawRequest($prepayId, $tool);
    }

    /**
     * Creates the request object for creating an order.
     *
     * @param array $orderData The data for the order.
     * @param Tool  $tool      The Tool instance.
     * @return string The JSON encoded request object.
     */
    private function createRequestObject(array $orderData, Tool $tool)
    {
        $req = [
            'nonce_str' => $tool->createNonceStr(),
            'method' => 'payment.preorder',
            'timestamp' => $tool->createTimeStamp(),
            'version' => '1.0',
        ];

        $bizDefaults = [
            'business_type' => 'BuyGoods',
            'trade_type' => 'InApp',
            'trans_currency' => 'ETB',
            'timeout_express' => '120m',
            'payee_identifier_type' => '04',
            'payee_type' => '5000',
            'payee_identifier' => $this->merchantCode,
        ];

        $bizContent = array_merge($bizDefaults, $orderData);

        $biz = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'merch_order_id' => $tool->createMerchantOrderId(),
            'title' => $bizContent['title'],
            'total_amount' => $bizContent['amount'],
            'notify_url' => $bizContent['notify_url'],
            'trade_type' => $bizContent['trade_type'],
            'timeout_express' => $bizContent['timeout_express'],
            'trans_currency' => $bizContent['trans_currency'],
            'business_type' => $bizContent['business_type'],
            'payee_identifier' => $bizContent['payee_identifier'],
            'payee_identifier_type' => $bizContent['payee_identifier_type'],
            'payee_type' => $bizContent['payee_type'],
        ];

        if (isset($bizContent['redirect_url'])) {
            $biz['redirect_url'] = $bizContent['redirect_url'];
        }

        $req['biz_content'] = $biz;
        $req['sign_type'] = 'SHA256WithRSA';
        $req['sign'] = $tool->sign($req);

        return json_encode($req);
    }

    /**
     * Creates the raw request string for the H5 page.
     *
     * @param string $prepayId The prepay ID.
     * @param Tool   $tool     The Tool instance.
     * @return string The raw request string.
     */
    private function createRawRequest($prepayId, Tool $tool)
    {
        $params = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'nonce_str' => $tool->createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => $tool->createTimeStamp(),
            'sign_type' => 'SHA256WithRSA',
        ];

        $queryString = http_build_query($params);
        $sign = $tool->sign($params);
        $rawRequest = $queryString . '&sign=' . urlencode($sign);

        return $rawRequest;
    }
}
