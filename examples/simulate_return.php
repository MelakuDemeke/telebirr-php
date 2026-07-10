<?php

/**
 * Generate a correctly-signed return URL for local testing of return.php,
 * without going through a real payment.
 *
 * It builds a sample "payment success" param set, signs it with your merchant
 * private key (the same signing process the library uses), and prints a
 * localhost URL you can open. It also self-checks that the signature verifies
 * with your current config — which doubles as a check that your configured
 * public key matches your signing key.
 *
 *   php examples/simulate_return.php
 *
 * NOTE: this simulates a return signed with YOUR merchant key. A real Telebirr
 * return is signed by Telebirr; if their signature is made with a different key,
 * verification uses the TELEBIRR_PUBLIC_KEY you configure.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Melaku\Telebirr\Signer;
use Melaku\Telebirr\SignatureVerifier;

$config = telebirr_config();

// A realistic success payload. merch_order_id can be one you saw from checkout.php.
$merchOrderId = $argv[1] ?? 'TESTORDER123';

$params = [
    'appid'            => $config->merchantAppId,
    'merch_code'       => $config->merchantCode,
    'merch_order_id'   => $merchOrderId,
    'payment_order_id' => 'PO' . time(),
    'total_amount'     => '1.00',
    'trans_currency'   => 'ETB',
    'trade_status'     => 'PAY_SUCCESS',
    'trans_end_time'   => (string) time(),
];

// Sign with the merchant private key (flat param set, same canonicalization the
// verifier expects: all fields except sign/sign_type, sorted).
$signer = new Signer($config);
$params['sign'] = $signer->signRequestObject($params);
$params['sign_type'] = 'SHA256WithRSA';

$base = $config->redirectUrl ?: 'http://localhost:8000/return.php';
$url = $base . '?' . http_build_query($params);

// Self-check verification against the current config.
$verifies = SignatureVerifier::verify($params, $config);

echo "Signed return URL (open in a browser while the local server is running):\n\n";
echo $url . "\n\n";
echo "Signature verifies with current config: " . ($verifies ? "YES ✅" : "NO ❌") . "\n";
if (!$verifies) {
    echo "\nIf NO: your configured TELEBIRR_PUBLIC_KEY does not match the private key\n";
    echo "used to sign here. For this local simulation, either leave TELEBIRR_PUBLIC_KEY\n";
    echo "blank (the library then derives the public key from your private key) or set it\n";
    echo "to your private key's matching public key.\n";
}
