<?php

namespace Melaku\Telebirr;

use Melaku\Telebirr\Utils\Tool;

/**
 * Main Telebirr class for handling payments.
 */
class Telebirr
{
    /**
     * The base URL for the Telebirr API.
     *
     * @var string
     */
    private $baseUrl;

    /**
     * The Fabric App ID provided by Telebirr.
     *
     * @var string
     */
    private $fabricAppId;

    /**
     * The App Secret provided by Telebirr.
     *
     * @var string
     */
    private $appSecret;

    /**
     * The Merchant App ID provided by Telebirr.
     *
     * @var string
     */
    private $merchantAppId;

    /**
     * The Merchant Code provided by Telebirr.
     *
     * @var string
     */
    private $merchantCode;

    /**
     * The public key for signing requests.
     *
     * @var string
     */
    private $publicKey;

    /**
     * The private key for signing requests.
     *
     * @var string
     */
    private $privateKey;


    /**
     * Telebirr constructor.
     *
     * @param array $config The configuration array.
     */
    public function __construct(array $config)
    {
        $this->baseUrl = $config['baseUrl'];
        $this->fabricAppId = $config['fabricAppId'];
        $this->appSecret = $config['appSecret'];
        $this->merchantAppId = $config['merchantAppId'];
        $this->merchantCode = $config['merchantCode'];
        $this->publicKey = $config['publicKey'];
        $this->privateKey = $config['privateKey'];
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
     * @return mixed The response from the API.
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

        return json_decode($response, true);
    }

    /**
     * Creates a new order.
     *
     * @param array $orderData The data for the order. This now accepts all biz_content fields.
     * @return mixed The response from the API.
     */
    public function createOrder(array $orderData)
    {
        $tool = $this->getTool();
        $tokenData = $this->applyFabricToken();
        if (!isset($tokenData['token'])) {
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
        $prepayId = json_decode($response)->biz_content->prepay_id;

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

        // --- This is the updated, flexible part ---
        // Set default values for the biz_content
        $bizDefaults = [
            'business_type' => 'BuyGoods',
            'trade_type' => 'InApp',
            'trans_currency' => 'ETB',
            'timeout_express' => '120m',
            'payee_identifier_type' => '04',
            'payee_type' => '5000',
            'payee_identifier' => $this->merchantCode,
        ];

        // Merge user-provided data with defaults. User data will overwrite defaults.
        $bizContent = array_merge($bizDefaults, $orderData);

        // Build the final biz_content object
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
        
        // Add optional redirect_url if the user provided it
        if (isset($bizContent['redirect_url'])) {
            $biz['redirect_url'] = $bizContent['redirect_url'];
        }
        // --- End of updated section ---

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
        $maps = [
            'appid' => $this->merchantAppId,
            'merch_code' => $this->merchantCode,
            'nonce_str' => $tool->createNonceStr(),
            'prepay_id' => $prepayId,
            'timestamp' => $tool->createTimeStamp(),
            'sign_type' => 'SHA256WithRSA',
        ];

        $rawRequest = '';
        foreach ($maps as $map => $m) {
            $rawRequest .= $map . '=' . $m . '&';
        }
        $sign = $tool->sign($maps);
        $rawRequest .= 'sign=' . urlencode($sign);

        return $rawRequest;
    }
}
