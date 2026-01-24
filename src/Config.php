<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

use Melaku\Telebirr\Exceptions\ConfigurationException;
use Melaku\Telebirr\ParameterValidator;

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

    /**
     * Validate configuration completeness and correctness
     * 
     * Checks all required fields and validates formats
     * 
     * @param bool $throwException If true, throws exception on validation failure
     * @return bool True if configuration is valid
     * @throws ConfigurationException if validation fails and throwException is true
     */
    public function validate(bool $throwException = true): bool
    {
        $errors = [];

        // Required fields
        if (empty($this->fabricAppId)) {
            $errors[] = "fabricAppId is required";
        }
        if (empty($this->appSecret)) {
            $errors[] = "appSecret is required";
        }
        if (empty($this->merchantAppId)) {
            $errors[] = "merchantAppId is required";
        }
        if (empty($this->merchantCode)) {
            $errors[] = "merchantCode is required";
        }
        if (empty($this->privateKey)) {
            $errors[] = "privateKey is required";
        }
        if (empty($this->notifyUrl)) {
            $errors[] = "notifyUrl is required";
        }

        // Validate private key format
        if (!empty($this->privateKey)) {
            if (!preg_match('/-----BEGIN PRIVATE KEY-----/', $this->privateKey)) {
                $errors[] = "privateKey must be in PEM format (should start with '-----BEGIN PRIVATE KEY-----')";
            }
            if (!preg_match('/-----END PRIVATE KEY-----/', $this->privateKey)) {
                $errors[] = "privateKey must be in PEM format (should end with '-----END PRIVATE KEY-----')";
            }
        }

        // Validate URLs
        if (!empty($this->notifyUrl)) {
            try {
                ParameterValidator::validateUrl($this->notifyUrl, 'notifyUrl');
            } catch (\Exception $e) {
                $errors[] = "notifyUrl validation failed: " . $e->getMessage();
            }
        }

        if (!empty($this->redirectUrl)) {
            try {
                ParameterValidator::validateUrl($this->redirectUrl, 'redirectUrl');
            } catch (\Exception $e) {
                $errors[] = "redirectUrl validation failed: " . $e->getMessage();
            }
        }

        // Validate merchant code format (should be 6 digits)
        if (!empty($this->merchantCode) && !preg_match('/^\d{6}$/', $this->merchantCode)) {
            $errors[] = "merchantCode should be 6 digits (got: '{$this->merchantCode}')";
        }

        if (!empty($errors)) {
            if ($throwException) {
                throw new ConfigurationException($errors);
            }
            return false;
        }

        return true;
    }

    /**
     * Check if all required fields are set
     * 
     * @return bool True if all required fields are present
     */
    public function isComplete(): bool
    {
        return !empty($this->fabricAppId) &&
            !empty($this->appSecret) &&
            !empty($this->merchantAppId) &&
            !empty($this->merchantCode) &&
            !empty($this->privateKey) &&
            !empty($this->notifyUrl);
    }

    /**
     * Get missing required fields
     * 
     * @return array Array of missing field names
     */
    public function getMissingFields(): array
    {
        $missing = [];

        if (empty($this->fabricAppId)) {
            $missing[] = 'fabricAppId';
        }
        if (empty($this->appSecret)) {
            $missing[] = 'appSecret';
        }
        if (empty($this->merchantAppId)) {
            $missing[] = 'merchantAppId';
        }
        if (empty($this->merchantCode)) {
            $missing[] = 'merchantCode';
        }
        if (empty($this->privateKey)) {
            $missing[] = 'privateKey';
        }
        if (empty($this->notifyUrl)) {
            $missing[] = 'notifyUrl';
        }

        return $missing;
    }

    /**
     * Validate environment setting
     * 
     * @return string Validated environment ('test' or 'production')
     * @throws \InvalidArgumentException if environment is invalid
     */
    public function validateEnvironment(): string
    {
        $env = $this->getEnvironment();
        if ($env === 'unknown') {
            throw new \InvalidArgumentException(
                "Unable to determine environment from baseUrl: '{$this->baseUrl}'. " .
                    "Use Config::forTest() or Config::forProduction() to set environment explicitly."
            );
        }
        return $env;
    }
}
