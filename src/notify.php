<?php

namespace Melaku\Telebirr;

/**
 * Telebirr payment notification helper (Legacy API).
 *
 * This class is for decrypting legacy Telebirr payment notifications that use
 * RSA-encrypted data. The new H5 C2B Web Payment Integration uses JSON notifications
 * (see Notify_Callback documentation), so this class is only needed for older API integrations.
 *
 * For H5 C2B notifications, see the Notify_Callback documentation:
 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Notify_Callback
 *
 * @deprecated For new integrations, use the H5 C2B Web Payment Integration which sends JSON notifications
 */
class Notify
{
	private $publicKey;
	private $data;

	/**
	 * @param string $publicKey Public key provided from Telebirr (base64, without PEM headers)
	 * @param string $data      Encrypted data returned from Telebirr (base64-encoded)
	 */
	function __construct($publicKey, $data)
	{
		$this->publicKey = $publicKey;
		$this->data = $data;
	}

	/**
	 * Decrypt and return payment information.
	 *
	 * Decrypts the RSA-encrypted payment notification data using the public key.
	 * Returns the decrypted JSON string which can then be parsed.
	 *
	 * @return string Decrypted JSON string containing payment information
	 * @throws \RuntimeException if public key is invalid or decryption fails
	 */
	function getPaymentInfo()
	{
		$pubPem = chunk_split($this->publicKey, 64, "\n");
		$pubPem = "-----BEGIN PUBLIC KEY-----\n" . $pubPem . "-----END PUBLIC KEY-----\n";
		$public_key = openssl_pkey_get_public($pubPem);
		if (!$public_key) {
			throw new \RuntimeException('Invalid public key');
		}
		$decrypted = ''; // decode must be done before splitting for getting the binary String
		$data = str_split(base64_decode($this->data), 256);
		foreach ($data as $chunk) {
			$partial = ''; // be sure to match padding
			$decryptionOK = openssl_public_decrypt($chunk, $partial, $public_key, OPENSSL_PKCS1_PADDING);
			if ($decryptionOK === false) {
				throw new \RuntimeException('Decryption failed');
			}
			$decrypted .= $partial;
		}
		return $decrypted;
	}
}

?>