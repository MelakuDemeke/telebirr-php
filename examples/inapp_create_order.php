<?php

/**
 * In-App SDK "create order" endpoint for a mobile app (Flutter, Android, iOS).
 *
 * The app POSTs the order, this endpoint creates it with Telebirr and answers
 * with the receiveCode the app hands to the Telebirr SDK.
 *
 * Serve the examples folder and point the app at this file:
 *   php -S 0.0.0.0:8000 -t examples
 *   POST http://<your-ip>:8000/inapp_create_order.php
 *
 * Request  (JSON or form): { "title": "Order 123", "amount": "100.00" }
 * Response (JSON):         { "receiveCode": "...", "merchOrderId": "...", "prepayId": "..." }
 *
 * Credentials are read from examples/.env, as for checkout.php. The app must be
 * built for the same environment as TELEBIRR_ENVIRONMENT.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Melaku\Telebirr\Telebirr;
use Melaku\Telebirr\Exceptions\ApiException;
use Melaku\Telebirr\Exceptions\InvalidParameterException;
use Melaku\Telebirr\Exceptions\TelebirrExceptionInterface;

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['error' => 'POST a JSON body: {"title": "...", "amount": "..."}']);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// In a real app, look the order up by id and take the title and amount from
// YOUR database. Never charge an amount the client sent.
$title  = trim((string) ($input['title'] ?? ''));
$amount = trim((string) ($input['amount'] ?? ''));
if ($title === '' || $amount === '') {
    respond(400, ['error' => 'title and amount are required']);
}

try {
    $client = new Telebirr(telebirr_config());
    $result = $client->createInAppPayment($title, $amount);

    // Persist $result->getMerchOrderId() against your order here, BEFORE
    // answering: it is how you match the notification to the order.

    respond(200, $result->toArray());
} catch (InvalidParameterException $e) {
    respond(400, ['error' => $e->getMessage()]);
} catch (ApiException $e) {
    error_log('Telebirr API error: ' . $e->getMessage());
    respond(502, ['error' => 'Telebirr rejected the order', 'code' => $e->getErrorCode()]);
} catch (TelebirrExceptionInterface $e) {
    error_log('Telebirr error: ' . $e->getMessage());
    respond(500, ['error' => 'Could not create the order']);
}
