<?php

/**
 * Notification endpoint (server-to-server callback from Telebirr).
 *
 * NOTE: Telebirr's servers cannot reach http://localhost, so this will only be
 * hit locally (e.g. curl) during testing — not by the real gateway. To receive
 * live callbacks you need a public URL (a deployed host, or a tunnel like
 * ngrok/cloudflared pointing at this server).
 *
 * It parses the JSON body, verifies the signature (fails closed), logs the
 * outcome, and returns a proper acknowledgement.
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use Melaku\Telebirr\NotificationHandler;

$config = telebirr_config();

$raw = file_get_contents('php://input') ?: '';
error_log('[telebirr notify] raw body: ' . $raw);

try {
    $notification = NotificationHandler::parse($raw);
} catch (\Throwable $e) {
    NotificationHandler::respondError('Invalid JSON: ' . $e->getMessage(), 400)->send();
    exit;
}

if (!NotificationHandler::verify($notification, $config)) {
    error_log('[telebirr notify] signature verification FAILED');
    NotificationHandler::respondError('Invalid signature', 400)->send();
    exit;
}

if (NotificationHandler::isPaymentSuccessful($notification)) {
    $info = NotificationHandler::extractPaymentInfo($notification);
    error_log('[telebirr notify] PAID: ' . json_encode($info));
    // TODO: fulfill the order for $info['merchantOrderId'] here.
}

NotificationHandler::respondSuccess('received')->send();
