<?php

/**
 * UI checkout page.
 *
 * GET  -> shows a payment form (title + amount).
 * POST -> creates the Telebirr order and shows a "Pay with Telebirr" button
 *         linking to the real checkout URL (plus the order details).
 *
 * Served by: php -S localhost:8000 -t examples
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_ui.php';

use Melaku\Telebirr\Telebirr;
use Melaku\Telebirr\Exceptions\ApiException;
use Melaku\Telebirr\Exceptions\TelebirrExceptionInterface;

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if (!$isPost) {
    // ---- Show the form ----
    $title  = htmlspecialchars($_GET['title'] ?? 'Test Order');
    $amount = htmlspecialchars($_GET['amount'] ?? '1.00');

    echo ui_header('Telebirr Checkout');
    echo <<<HTML
    <p class="sub">Create a test payment and jump to the Telebirr checkout page.</p>
    <form method="post" action="/">
      <label for="title">Order title</label>
      <input id="title" name="title" value="{$title}" placeholder="Test Order" required>

      <label for="amount">Amount (ETB)</label>
      <input id="amount" name="amount" value="{$amount}" inputmode="decimal" placeholder="1.00" required>

      <button class="btn" type="submit">Create checkout →</button>
    </form>
    <div class="note">Environment: test · TLS verification is disabled for the sandbox
      (its certificate is expired). Never disable it in production.</div>
HTML;
    echo ui_footer();
    exit;
}

// ---- Handle submission: create the order ----
$title  = trim((string) ($_POST['title'] ?? 'Test Order'));
$amount = trim((string) ($_POST['amount'] ?? '1.00'));

$config = telebirr_config();
$config->validate();
$client = new Telebirr($config);

echo ui_header('Telebirr Checkout');

try {
    $result = $client->createCheckoutUrl($title, $amount, null);

    $url  = htmlspecialchars($result->getCheckoutUrl());
    $mid  = htmlspecialchars($result->getMerchOrderId());
    $pid  = htmlspecialchars($result->getPrepayId());
    $amtE = htmlspecialchars($amount);
    $ttlE = htmlspecialchars($title);

    echo <<<HTML
    <span class="badge ok">✓ Order created</span>
    <dl>
      <dt>Title</dt><dd>{$ttlE}</dd>
      <dt>Amount</dt><dd>{$amtE} ETB</dd>
      <dt>merch_order_id</dt><dd>{$mid}</dd>
      <dt>prepay_id</dt><dd>{$pid}</dd>
    </dl>
    <a class="btn" href="{$url}">Pay with Telebirr →</a>
    <a class="btn secondary" href="/?title={$ttlE}&amount={$amtE}">Start over</a>
    <div class="note">After paying you'll be redirected to
      <code>/return.php</code>, which verifies the signature and shows the result.</div>
    <details><summary>Show full checkout URL</summary><pre>{$url}</pre></details>
HTML;
} catch (ApiException $e) {
    $msg = htmlspecialchars($e->getMessage());
    $code = htmlspecialchars((string) $e->getErrorCode());
    $http = htmlspecialchars((string) $e->getHttpStatus());
    echo <<<HTML
    <span class="badge err">✕ Telebirr API error</span>
    <dl><dt>HTTP</dt><dd>{$http}</dd><dt>Error code</dt><dd>{$code}</dd></dl>
    <pre>{$msg}</pre>
    <a class="btn secondary" href="/">Back</a>
HTML;
} catch (TelebirrExceptionInterface $e) {
    $msg = htmlspecialchars($e->getMessage());
    echo <<<HTML
    <span class="badge err">✕ Error</span>
    <pre>{$msg}</pre>
    <a class="btn secondary" href="/">Back</a>
HTML;
}

echo ui_footer();
