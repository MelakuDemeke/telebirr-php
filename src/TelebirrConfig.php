<?php

namespace Melaku\Telebirr;

class TelebirrConfig
{
    private $merchantAppId;
    private $fabricAppId;
    private $shortCode;
    private $appSecret;
    private $privateKey;
    private $apiBaseUrl = 'https://196.188.120.3:38443/apiaccess/payment/gateway';
    private $h5CheckoutUrl = 'https://196.188.120.3:11443/ammwebpay/#/?';

    public function __construct(
        string $merchantAppId,
        string $fabricAppId,
        string $shortCode,
        string $appSecret,
        string $privateKey
    ) {
        $this->merchantAppId = $merchantAppId;
        $this->fabricAppId = $fabricAppId;
        $this->shortCode = $shortCode;
        $this->appSecret = $appSecret;
        $this->privateKey = $privateKey;
    }

    public function getMerchantAppId(): string { return $this->merchantAppId; }
    public function getFabricAppId(): string { return $this->fabricAppId; }
    public function getShortCode(): string { return $this->shortCode; }
    public function getAppSecret(): string { return $this->appSecret; }
    public function getPrivateKey(): string { return $this->privateKey; }
    public function getApiBaseUrl(): string { return $this->apiBaseUrl; }
    public function getH5CheckoutUrl(): string { return $this->h5CheckoutUrl; }
}