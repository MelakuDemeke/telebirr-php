<?php

/**
 * Manual checkout test.
 *
 * Generates a real Telebirr checkout URL so you can click through the flow.
 *
 * Quick start:
 *   cp examples/.env.example examples/.env   # then fill in examples/.env
 *   php examples/checkout.php "My test order" 1.00
 *
 * Credentials are read from examples/.env (loaded automatically). You can also
 * set them as real environment variables, which take precedence over .env.
 *
 * Defaults to the TEST environment. Set TELEBIRR_ENVIRONMENT=production to go live.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Melaku\Telebirr\Telebirr;
use Melaku\Telebirr\Exceptions\ApiException;
use Melaku\Telebirr\Exceptions\TelebirrExceptionInterface;

$config = telebirr_config();

// Fail fast on obviously bad config.
$config->validate();

// --- Order details (CLI args, with defaults) ---
$title  = $argv[1] ?? 'Test Order';
$amount = $argv[2] ?? '1.00';

$client = new Telebirr($config);

echo "Environment : {$config->getEnvironment()}\n";
echo "Title       : {$title}\n";
echo "Amount      : {$amount} ETB\n";
echo "Notify URL  : {$config->notifyUrl}\n";
echo "Redirect URL: " . ($config->redirectUrl ?? '(none)') . "\n";
echo "Creating checkout...\n\n";

try {
    // Pass null for merchOrderId to have a valid one generated and returned.
    $result = $client->createCheckoutUrl($title, $amount, null);

    echo "Success!\n";
    echo "  merch_order_id : {$result->getMerchOrderId()}   <-- persist THIS against your order\n";
    echo "  prepay_id      : {$result->getPrepayId()}\n";
    echo "  checkout URL   :\n{$result->getCheckoutUrl()}\n\n";
    echo "Open the checkout URL in a browser to complete the payment.\n";
} catch (ApiException $e) {
    fwrite(STDERR, "Telebirr API error (HTTP {$e->getHttpStatus()}, code {$e->getErrorCode()}):\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (TelebirrExceptionInterface $e) {
    fwrite(STDERR, "Telebirr error: " . $e->getMessage() . "\n");
    exit(1);
}
