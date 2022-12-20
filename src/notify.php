<?php

namespace Melaku\Telebirr;

/**
 * Telebirr payment helper
 * 
 * @publicKey		public key provided form tele
 * @data			data return from telebirr
 * 	
 */

class Notify
{
	private $publicKey;
	private $data;

	function __construct(
		$publicKey,
		$data
	)
	{
		$this->publicKey = $publicKey;
		$this->data = $data;
	}

	/**
	 * getPaymentInfo returns PaymentInformation
	 */

	 function getPaymentInfo()
{
    $pubPem = chunk_split($this->publicKey, 64, "\n");
    $pubPem = "-----BEGIN PUBLIC KEY-----\n" . $pubPem . "-----END PUBLIC KEY-----\n";
    $public_key = openssl_pkey_get_public($pubPem);
    if (!$public_key) {
        die('invalid public key');
    }
    $decrypted = ''; //decode must be done before spliting for getting the binary String
    $data = str_split(base64_decode($this->data), 256);
    foreach ($data as $chunk) {
        $partial = ''; //be sure to match padding
        $decryptionOK = openssl_public_decrypt($chunk, $partial, $public_key, OPENSSL_PKCS1_PADDING);
        if ($decryptionOK === false) {
            die('fail');
        }
        $decrypted .= $partial;
    }
    return $decrypted;
}
	
}

?>