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
    public ?string $redirectUrl;
    public ?string $telebirrPublicKey;

    // Telebirr URLs
    private const BASE_URL_TEST = 'https://developerportal.ethiotelebirr.et:38443/apiaccess/payment/gateway';
    private const BASE_URL_PRODUCTION = 'https://telebirrappcube.ethiomobilemoney.et:38443/apiaccess/payment/gateway';
    private const WEB_BASE_URL_TEST = 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?';
    private const WEB_BASE_URL_PRODUCTION = 'https://telebirrappcube.ethiomobilemoney.et:38443/payment/web/paygate?';

    public function __construct(array $options)
    {
        // Handle environment-based URL selection
        if (isset($options['environment'])) {
            $this->setEnvironmentUrls($options['environment']);
        } else {
            // Manual URL configuration (backward compatible)
            $this->baseUrl    = $options['baseUrl'] ?? self::BASE_URL_TEST;
            $this->webBaseUrl = $options['webBaseUrl'] ?? self::WEB_BASE_URL_TEST;
        }

        $this->fabricAppId   = $options['fabricAppId'];
        $this->appSecret     = $options['appSecret'];
        $this->merchantAppId = $options['merchantAppId'];
        $this->merchantCode  = $options['merchantCode'];
        $this->privateKey    = $options['privateKey'];

        if (empty($options['notifyUrl'])) {
            throw new \InvalidArgumentException('notifyUrl is required. This is where Telebirr will send payment status updates.');
        }
        $this->notifyUrl = $options['notifyUrl'];

        // redirectUrl is optional - where Telebirr redirects user after payment
        $this->redirectUrl = $options['redirectUrl'] ?? null;
        
        // telebirrPublicKey is optional - used for verifying signatures from return URLs and notifications
        $this->telebirrPublicKey = $options['telebirrPublicKey'] ?? null;
    }

    /**
     * Set URLs based on environment (test or production)
     *
     * @param string $environment 'test' or 'production'
     * @return void
     * @throws \InvalidArgumentException if environment is invalid
     */
    private function setEnvironmentUrls(string $environment): void
    {
        $env = strtolower($environment);
        
        if ($env === 'test' || $env === 'development' || $env === 'dev' || $env === 'sandbox') {
            $this->baseUrl    = self::BASE_URL_TEST;
            $this->webBaseUrl = self::WEB_BASE_URL_TEST;
        } elseif ($env === 'production' || $env === 'prod' || $env === 'live') {
            $this->baseUrl    = self::BASE_URL_PRODUCTION;
            $this->webBaseUrl = self::WEB_BASE_URL_PRODUCTION;
        } else {
            throw new \InvalidArgumentException(
                "Invalid environment '{$environment}'. Must be 'test' or 'production'."
            );
        }
    }

    /**
     * Create config for test/development environment
     *
     * @param array $options Configuration options (without baseUrl/webBaseUrl)
     * @return self
     */
    public static function forTest(array $options): self
    {
        $options['environment'] = 'test';
        return new self($options);
    }

    /**
     * Create config for production environment
     *
     * @param array $options Configuration options (without baseUrl/webBaseUrl)
     * @return self
     */
    public static function forProduction(array $options): self
    {
        $options['environment'] = 'production';
        return new self($options);
    }

    /**
     * Create config with automatic environment detection
     * 
     * Detects environment from:
     * 1. TELEBIRR_ENVIRONMENT environment variable
     * 2. APP_ENV environment variable
     * 3. Defaults to 'test' if not set
     *
     * @param array $options Configuration options
     * @return self
     */
    public static function fromEnvironment(array $options): self
    {
        // Check for explicit environment setting
        if (!isset($options['environment'])) {
            // Try TELEBIRR_ENVIRONMENT first
            $env = getenv('TELEBIRR_ENVIRONMENT');
            
            // Fall back to APP_ENV
            if ($env === false) {
                $env = getenv('APP_ENV');
            }
            
            // Default to test if not set
            if ($env === false) {
                $env = 'test';
            }
            
            $options['environment'] = $env;
        }
        
        return new self($options);
    }

    /**
     * Get the current environment (test or production) based on baseUrl
     *
     * @return string 'test' or 'production'
     */
    public function getEnvironment(): string
    {
        if (strpos($this->baseUrl, 'developerportal') !== false) {
            return 'test';
        } elseif (strpos($this->baseUrl, 'telebirrappcube') !== false) {
            return 'production';
        }
        
        // Unknown environment
        return 'unknown';
    }

    /**
     * Check if current config is for test environment
     *
     * @return bool
     */
    public function isTest(): bool
    {
        return $this->getEnvironment() === 'test';
    }

    /**
     * Check if current config is for production environment
     *
     * @return bool
     */
    public function isProduction(): bool
    {
        return $this->getEnvironment() === 'production';
    }
}
