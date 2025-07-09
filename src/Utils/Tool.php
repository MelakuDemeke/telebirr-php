<?php

namespace Melaku\Telebirr\Utils;

// Ensure you have phpseclib installed: composer require phpseclib/phpseclib
use phpseclib3\Crypt\RSA;

/**
 * Utility class for Telebirr integration.
 */
class Tool
{
    /**
     * The private key for signing requests.
     *
     * @var string
     */
    private $privateKey;

    /**
     * Tool constructor.
     *
     * @param string $privateKey The private key for signing.
     */
    public function __construct($privateKey)
    {
        $this->privateKey = $privateKey;
    }

    /**
     * Sends a request to the given URL using cURL.
     *
     * @param string $url The URL to send the request to.
     * @param mixed  $data The data to send with the request.
     * @param array  $headers The headers to send with the request.
     * @return bool|string The response from the server.
     */
    public function sendRequest($url, $data, $headers)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // For development environment only, disable SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $server_output = curl_exec($ch);
        curl_close($ch);
        return $server_output;
    }


    /**
     * Signs the given data using RSA with SHA256.
     *
     * @param array $data The data to sign.
     * @return string The base64 encoded signature.
     */
    public function sign($data)
    {
        $stringApplet = $this->prepareString($data);
        $sortedString = $this->sortedString($stringApplet);
        return $this->signWithRSA($sortedString);
    }

    /**
     * Prepares the string for signing by sorting and concatenating the data.
     *
     * @param array $data The data to prepare.
     * @return string The prepared string.
     */
    private function prepareString($data)
    {
        $exclude_fields = ["sign", "sign_type", "header", "refund_info", "openType", "raw_request"];
        ksort($data);
        $stringApplet = '';
        foreach ($data as $key => $values) {
            if (in_array($key, $exclude_fields)) {
                continue;
            }

            if (is_array($values)) {
                ksort($values);
                foreach ($values as $valueKey => $single_value) {
                     $stringApplet .= ($stringApplet == '') ? "$valueKey=$single_value" : "&$valueKey=$single_value";
                }
            } else {
                 $stringApplet .= ($stringApplet == '') ? "$key=$values" : "&$key=$values";
            }
        }
        return $stringApplet;
    }

    /**
     * Sorts the string alphabetically.
     *
     * @param string $stringApplet The string to sort.
     * @return string The sorted string.
     */
    private function sortedString($stringApplet)
    {
        $stringExplode = '';
        $sortedArray = explode("&", $stringApplet);
        sort($sortedArray);
        foreach ($sortedArray as $x_value) {
            $stringExplode .= ($stringExplode == '') ? $x_value : '&' . $x_value;
        }
        return $stringExplode;
    }

    /**
     * Signs the given data with RSA using phpseclib v3.
     *
     * @param string $data The data to sign.
     * @return string The base64 encoded signature.
     */
    private function signWithRSA($data)
    {
        try {
            /** @var \phpseclib3\Crypt\RSA\PrivateKey $private */
            $private = RSA::load($this->privateKey);
            $private = $private->withHash('sha256')->withMGFHash('sha256');
            $signature = $private->sign($data);
            return base64_encode($signature);
        } catch (\Exception $e) {
            // In a real application, you should log the error message.
            // For example: error_log('Error signing data: ' . $e->getMessage());
            return "Error signing data.";
        }
    }

    /**
     * Creates a unique merchant order ID.
     *
     * @return string The merchant order ID.
     */
    public function createMerchantOrderId()
    {
        return (string)time();
    }

    /**
     * Creates a timestamp.
     *
     * @return string The timestamp.
     */
    public function createTimeStamp()
    {
        return (string)time();
    }

    /**
     * Creates a random nonce string.
     *
     * @return string The nonce string.
     */
    public function createNonceStr()
    {
        return uniqid();
    }
}
