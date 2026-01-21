<?php

namespace Melaku\Telebirr;

class Config
{
    public string $baseUrl;
    public string $webBaseUrl;
    public string $fabricAppId;
    public string $appSecret;
    public string $merchantAppId;
    public string $merchantCode;
    public string $privateKey;
    public string $notifyUrl;

    public function __construct(array $options)
    {
        $this->baseUrl       = $options['baseUrl'];
        $this->webBaseUrl    = $options['webBaseUrl'];
        $this->fabricAppId   = $options['fabricAppId'];
        $this->appSecret     = $options['appSecret'];
        $this->merchantAppId = $options['merchantAppId'];
        $this->merchantCode  = $options['merchantCode'];
        $this->privateKey    = $options['privateKey'];
        
        if (empty($options['notifyUrl'])) {
            throw new \InvalidArgumentException('notifyUrl is required. This is where Telebirr will send payment status updates.');
        }
        $this->notifyUrl = $options['notifyUrl'];
    }
}
