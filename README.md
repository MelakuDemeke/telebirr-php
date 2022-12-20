<a href="https://aimeos.org/">
    <img src="img/telebirrlogo.png" alt="Telebirr" title="Aimeos" align="right" height="60" />
</a>


# Telebirr Php library v 0.1
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

- [Telebirr Php library v 0.1](#telebirr-php-library-v-01)
  - [Table of content](#table-of-content)
  - [Installation](#installation)
    - [Composer](#composer)
  - [Usage](#usage)
    - [Required information's](#required-informations)
    - [General setup](#general-setup)
    - [To initialize payment](#to-initialize-payment)
    - [Decrypt payment data](#decrypt-payment-data)
## Installation
### Composer
``` 
composer require melaku/telebirr 
```

## Usage

### Required information's
you will receive the required information from Tele with information which looks like theis :arrow_down:

| merchant name   | short code   |  APP ID | APP KEY  |  Public ID | H5  | InApp Payment   |
|---|---|---|---|---|---|---|
| owner name  | 6-digit code  | 32-character Id  | 32-character key  | 392-character public key  | web payment url  | mobile payment url  |

you should store those information in your development environment like `.env` file

### General setup
you should always require the composer autoload
```PHP
require 'vendor/autoload.php';
```

### To initialize payment

- step 1 Initialize the information from Telebirr in variable
  - ```PHP
      $PUBLICKEY = "YOUR PUBLIC KEY";
      $APPKEY = "YOUR APP KEY";
      $APPID = "YOUR APP ID";
      $SHORTCODE = "YOUR SHORT CODE";
      $API = "YOUR WEBPAY URL";
    ```
- step 2 Initialize information from your side in a variable
  - ```PHP
      $NOTIFYURL = "http://YOUR/NOTIFY/URL";
      $RETURNURL = "http://YOUR/RERURN/URL";
      $TIMEOUT = '30';
      $RECIVER = "COMPANY NAME";
      $totalAmount = 3;
      $subject = "REASON FOR PAYMENT";
    ```
  - explanation
    - `NOTIFYURL` - is the URL that will receive the payment status from telebirr and is responsible for updating your Database
    - `RETURNURL` - the URL that will be returned after payment usually it is the checkout screen
    - `TIMEOUT` - payment timeout, it is set 30 seconds by default
    - `RECIVER` - the company that will receive the payment
    - `totalAmount` - is the amount that should be paid, this information usually comes from POST so assign the value accordingly
    - `subject` - it is the reason for payment, eg. book purchase
  
- step 3  Create a new object of Telebirr class with all informations
  - ```PHP
      $pay1 = new Melaku\Telebirr\Telebirr(
        $PUBLICKEY,
        $APPKEY,
        $APPID,
        $API,
        $SHORTCODE,
        $NOTIFYURL, 
        $RETURNURL,
        $TIMEOUT,
        $RECIVER,
        $totalAmount,
        $subject,
      );
    ```
- step 4 get the payment url

  - ```PHP
      var_dump($pay1->getPyamentUrl());
    ```
  - this will return payment url like `http://196.188.120.3:11443/ammwebpay/#/?transactionNo=123456789` the transaction number `123456789` is used for example yours will be different
  - after this you are required to redirect your header to the payurl

    - ```PHP
        header("Location:" . $pay1->getPyamentUrl());
      ```
    - this will forward you to the payment screen
  
### Decrypt payment data 
- step 1 define public key and data received form telebirr

  - ```PHP
      $PUBLICKEY = "YOUR PUBLIC KEY";
      $data = "DATA RECIVED FROM TEELEBIRR VIA NOTIFY URL";
    ```
    - explanation
      - `PUBLICKEY` - is the same as the public key defined during payment initialization
      - `data` -  is the data received from telebirr from the notify URL, your notify URL should accept plain text not JSON, you can get incoming data by using ⬇️

        - ```PHP
            $data = file_get_contents('php://input');
          ```
- step 2  Create a new object of Notify class with `$PUBLICKEY` and `$data`

  - ```PHP
    $result = new \Melaku\Telebirr\Notify($PUBLICKEY,$data);
    ```
  - to get the payment result call `getPaymentInfo()`

    - ```PHP
      var_dump($result->getPaymentInfo());
      ```
  - The `getPaymentInfo()` will return a json data like ⬇️

    - ```JSON
      {
        "msisdn":"251900000032",
        "outTradeNo":"15380eaea1405302ee0821b62546682c",
        "totalAmount":"10",
        "tradeDate":1670051315108,
        "tradeNo":"202212031008041598937091913756674",
        "tradeStatus":2,
        "transactionNo":"9L360NSV4U"
      }
      ```