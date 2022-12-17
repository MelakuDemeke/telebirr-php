<?php

namespace Melaku\Telebirr;

/**
 * Telebirr payment helper
 * 
 * @publicKey		public key provided form tele
 * @appKey			app key provided form tele
 * @appId			app id provided form tele
 * @api				payment getway provided form tele
 * @notifyUrl		your notify url which will get the after payment data
 * @returnUrl		your sucess page
 * @shortCode		short code form tele
 * @timeoutExpress  pyament timeout usually it is 30s 
 * @receiveName		the company name whos goingto recive the payment 
 * @totalAmount		the amount shuld be paid
 * @subject			payment subject
 * 	
 */

class Telebirr
{
	private $publicKey;
	private $appKey;
	private $appId;
	private $api;
	private $shortCode;
	private $notifyUrl;
	private $returnUrl;
	private $timeoutExpress;
	private $receiveName;
	private $totalAmount;
	private $subject;


	function __construct(
		$publicKey,
		$appKey,
		$appId,
		$api,
		$shortCode,
		$notifyUrl,
		$returnUrl,
		$timeoutExpress,
		$receiveName,
		$totalAmount,
		$subject
	)
	{
		$this->publicKey = $publicKey;
		$this->appKey = $appKey;
		$this->appId = $appId;
		$this->api = $api;
		$this->shortCode = $shortCode;
		$this->notifyUrl = $notifyUrl;
		$this->returnUrl = $returnUrl;
		$this->timeoutExpress = $timeoutExpress;
		$this->receiveName = $receiveName;
		$this->totalAmount = $totalAmount;
		$this->subject = $subject;
	}

	/**
	 * getPaymentUrl returns the to pay url
	 */

	public function getPyamentUrl()
	{
		$nonce = time();
		$str = rand();
		$result = md5($str);

		$data = [
			'outTradeNo' => $result,
			'subject' => $this->subject,
			'totalAmount' => $this->totalAmount,
			'shortCode' => $this->shortCode,
			'notifyUrl' => $this->notifyUrl,
			'returnUrl' => $this->returnUrl,
			'receiveName' => $this->receiveName,
			'appId' => $this->appId,
			'timeoutExpress' => $this->timeoutExpress,
			'nonce' => $result,
			'timestamp' => $nonce,
			'appKey' => $this->appKey
		];

		ksort($data);
		$StringA = '';
		foreach ($data as $k => $v) {
			if ($StringA == '') {
				$StringA = $k . '=' . $v;
			} else {
				$StringA = $StringA . '&' . $k . '=' . $v;
			}
		}
		$StringB = hash("sha256", $StringA);

		$sign = strtoupper($StringB);

		$ussdjson = json_encode($data);
		$ussd = $this->encryptRSA($ussdjson, $this->publicKey);
		$requestMessage = [
			'appid' => $this->appId,
			'sign' => $sign,
			'ussd' => $ussd
		];

		$curl = curl_init($this->api);
		curl_setopt($curl, CURLOPT_URL, $this->api);
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		
		$headers = array(
			"Accept: application/json",
			"Content-Type: application/json",
		);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		
		$data = json_encode($requestMessage);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		
		//for debug only!
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		
		$resp = curl_exec($curl);
		curl_close($curl);
		// var_dump($resp);
		
		
		$decode = json_decode($resp, true);
		$topayUrl = $decode['data']['toPayUrl'];
		
		return $topayUrl;
	}

	/**
	 * encryptRSA encrypt the data using the public key
	 * 
	 * @data	the data tobe encrypted
	 * @public	public key from telebirr
	 */

	private function encryptRSA($data, $public)
	{
		$pubPem = chunk_split($public, 64, "\n");
		$pubPem = "-----BEGIN PUBLIC KEY-----\n" . $pubPem . "-----END PUBLIC KEY-----\n";
		$public_key = openssl_pkey_get_public($pubPem);
	
		if (!$public_key) {
			die('invalid public key');
		}
		$crypto = '';
		foreach (str_split($data, 117) as $chunk) {
			$return = openssl_public_encrypt($chunk, $cryptoItem, $public_key);
			if (!$return) {
				return ('fail');
			}
			$crypto .= $cryptoItem;
		}
		$ussd = base64_encode($crypto);
		return $ussd;
	}
}

?>