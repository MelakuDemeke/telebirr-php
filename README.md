<a href="melaku.me">
    <img src="img/telebirrlogo.png" alt="Telebirr" title="Melaku" align="right" height="60" />
</a>


# Telebirr Php library v 1.0
![](img/telebanner.png)

![GitHub branch checks state](https://img.shields.io/github/checks-status/MelakuDemeke/telebirr-php/main)
![GitHub repo size](https://img.shields.io/github/repo-size/MelakuDemeke/telebirr-php)
![GitHub issues](https://img.shields.io/github/issues/MelakuDemeke/telebirr-php)
![Packagist Downloads](https://img.shields.io/packagist/dt/melaku/telebirr?color=green&logo=packagist&logoColor=white)
![Packagist Stars](https://img.shields.io/packagist/stars/melaku/telebirr?logo=packagist&logoColor=white)
![GitHub](https://img.shields.io/github/license/MelakuDemeke/telebirr-php?style=flat)
![GitHub Repo stars](https://img.shields.io/github/stars/MelakuDemeke/telebirr-php?logo=github&style=flat)
![GitHub forks](https://img.shields.io/github/forks/MelakuDemeke/telebirr-php?logo=github&style=falt)
![GitHub commit activity](https://img.shields.io/github/commit-activity/m/MelakuDemeke/telebirr-php?logo=github)
![GitHub last commit](https://img.shields.io/github/last-commit/MelakuDemeke/telebirr-php)

Telebirr-Php is a php library for [telebirr](https://www.ethiotelecom.et/telebirr/).  
Telebirr is a mobile money service developed by Huawei that is owned and was launched by Ethio telecom.  
This library will help you by providing an easy integration method so you can focus on your main task

## Table of content

- [Telebirr Php library v 1.0](#telebirr-php-library-v-10)
  - [Table of content](#table-of-content)
- [Telebirr PHP Library v1.0](#telebirr-php-library-v10)
  - [Installation](#installation)
  - [Configuration](#configuration)
  - [Usage](#usage)
    - [1. Creating a Web Checkout Payment](#1-creating-a-web-checkout-payment)
    - [2. Creating an In-App SDK Payment](#2-creating-an-in-app-sdk-payment)

# Telebirr PHP Library v1.0

Telebirr-Php is a modern PHP library for integrating with the [Telebirr](https://www.ethiotelecom.et/telebirr/) payment gateway. This library simplifies the entire payment process, including creating web and in-app orders, and securely verifying payment notifications.

## Installation

The recommended way to install the library is through [Composer](https://getcomposer.org/).

```bash
composer require melaku/telebirr
```

The library requires the following PHP extensions:

  - `php: >=7.4.0`
  - `ext-curl`
  - `ext-json`
  - `ext-openssl`

## Configuration

Before using the library, you need to set up your credentials. The recommended way is to create a `config.php` file that returns an array with your settings.

Create a `config.php` file:

```php
<?php
// config.php

return [
    // --- Your Merchant Credentials ---
    'baseUrl'       => 'https://196.188.120.3:38443/apiaccess/payment/gateway',
    'fabricAppId'   => 'YOUR_FABRIC_APP_ID',
    'appSecret'     => 'YOUR_APP_SECRET',
    'merchantAppId' => 'YOUR_MERCHANT_APP_ID',
    'shortCode'     => 'YOUR_MERCHANT_SHORT_CODE',

    /**
     * --- Your Private Key ---
     * This is the private key you recived from telebirr. The library can accept it
     * as a raw string or with the PEM headers.
     *
     * Option 1: Load from a file (Recommended)
     * 'privateKey' => file_get_contents(__DIR__ . '/private_key.pem'),
     *
     * Option 2: As a raw string from an environment variable
     * 'privateKey' => 'YOUR_RAW_PRIVATE_KEY_STRING',
     */
    'privateKey'    => file_get_contents(__DIR__ . '/private_key.pem'),

];
```

## Usage

Always include the Composer autoloader at the top of your script:

```php
require_once __DIR__ . '/vendor/autoload.php';

use Melaku\Telebirr\Telebirr;
```

### 1\. Creating a Web Checkout Payment

This is used to generate a payment URL for a standard web-based checkout flow.

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Melaku\Telebirr\Telebirr;

// Load configuration
$config = require __DIR__ . '/config.php';
$telebirr = new Telebirr($config);

// Prepare order data
$orderData = [
    'title'      => 'Online Store Purchase',
    'amount'     => '150.75',
    'notify_url' => 'https://yourdomain.com/notify.php',
    'trade_type' => 'Checkout', // Use 'Checkout' for web payments
];

// Create the order
$result = $telebirr->createOrder($orderData);

if (is_string($result) && strpos($result, 'prepay_id') !== false) {
    // Build the full payment URL
    $paymentUrl = 'https://developerportal.ethiotelebirr.et:38443/payment/web/paygate?' . $result . '&version=1.0&trade_type=Checkout';
    
    // Redirect the user to the payment page
    header("Location: " . $paymentUrl);
    exit;
} else {
    // Handle error
    var_dump($result);
}
```

### 2\. Creating an In-App SDK Payment

This is used when your mobile app's SDK needs to initiate a payment. The process returns a `receiveCode` that you must send to the mobile app.

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Melaku\Telebirr\Telebirr;

// Load configuration
$config = require __DIR__ . '/config.php';
$telebirr = new Telebirr($config);

// Prepare order data
$orderData = [
    'title'      => 'InApp Item Purchase',
    'amount'     => '25.00',
    'notify_url' => 'https://yourdomain.com/notify.php',
];

// Create the In-App order
$response = $telebirr->createInAppOrder($orderData);

// Send the JSON response to your mobile application
header('Content-Type: application/json');
echo json_encode($response);
```
