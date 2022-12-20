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

- [Installation](#installation)
  - [Composer](#composer)
- [Usage](#usage)
## Requirements
- PHP >= 7.4.0
- cURL Extension
- 
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

