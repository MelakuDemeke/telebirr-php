<?php

/**
 * Return URL landing page (where the user's browser comes back after payment).
 *
 * Verifies the signature (fails closed) and shows the parsed result in a styled
 * card. In a real app you would confirm the status server-to-server with
 * Telebirr::queryOrder() before fulfilling.
 *
 * Served by: php -S localhost:8000 -t examples
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_ui.php';

use Melaku\Telebirr\ReturnUrlHandler;

$config = telebirr_config();

// Telebirr returns via GET redirect, but accept POST too for robustness.
$params = !empty($_GET) ? $_GET : $_POST;

echo ui_header('Payment Result');

try {
    $data = ReturnUrlHandler::handle($params, $config);

    $success = (bool) $data['isSuccess'];
    $badge = $success
        ? '<span class="badge ok">✓ Payment successful</span>'
        : '<span class="badge err">✕ Not successful</span>';

    $status = htmlspecialchars((string) $data['tradeStatus']);
    $mid    = htmlspecialchars((string) $data['merchantOrderId']);
    $pid    = htmlspecialchars((string) $data['paymentOrderId']);
    $amount = htmlspecialchars((string) $data['amount']);
    $curr   = htmlspecialchars((string) $data['currency']);
    $raw    = htmlspecialchars(print_r($data['raw'], true));

    echo <<<HTML
    {$badge}
    <p class="sub" style="margin-top:12px">Signature verified.</p>
    <dl>
      <dt>Trade status</dt><dd>{$status}</dd>
      <dt>Merchant order id</dt><dd>{$mid}</dd>
      <dt>Payment order id</dt><dd>{$pid}</dd>
      <dt>Amount</dt><dd>{$amount} {$curr}</dd>
    </dl>
    <div class="note">Reminder: the return URL is spoofable in general — confirm the
      real status via <code>Telebirr::queryOrder()</code> before fulfilling.</div>
    <a class="btn secondary" href="/">New checkout</a>
    <details><summary>Raw parameters</summary><pre>{$raw}</pre></details>
HTML;
} catch (\Throwable $e) {
    http_response_code(400);
    $msg = htmlspecialchars($e->getMessage());
    $raw = htmlspecialchars(print_r($params, true));
    echo <<<HTML
    <span class="badge err">✕ Rejected</span>
    <pre>{$msg}</pre>
    <p class="sub">This is expected if you opened this page directly — there are no
      signed parameters to verify. Complete a payment (or use
      <code>simulate_return.php</code>) to see a verified result.</p>
    <a class="btn secondary" href="/">New checkout</a>
    <details><summary>Raw parameters</summary><pre>{$raw}</pre></details>
HTML;
}

echo ui_footer();
