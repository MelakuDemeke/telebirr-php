<?php

/**
 * Shared bootstrap for the example scripts.
 *
 * Loads examples/.env and builds a Config. Included by checkout.php (CLI),
 * notify.php and return.php (web endpoints).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Melaku\Telebirr\Config;

/**
 * Load a .env file (KEY=value per line) into the environment.
 * Does NOT override variables already set in the real environment.
 */
function loadEnv(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

/** Read a required env var or exit with a helpful message. */
function requireEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Missing required environment variable: {$name}\n");
        exit(1);
    }
    return $value;
}

/**
 * Wrap a bare base64 DER key body in PEM headers if it isn't PEM already.
 */
function toPem(string $raw, string $label): string
{
    $raw = trim($raw);
    if (strpos($raw, '-----BEGIN') !== false) {
        return $raw;
    }
    $body = preg_replace('/\s+/', '', $raw);
    return "-----BEGIN {$label}-----\n" . chunk_split($body, 64, "\n") . "-----END {$label}-----\n";
}

/**
 * Resolve a key from an inline var, a file path var, or a pasted key body.
 *
 * @return string|null PEM key, or null if neither var is set.
 */
function resolveKey(string $inlineVar, string $pathVar, string $label): ?string
{
    $inline = getenv($inlineVar);
    if ($inline !== false && trim($inline) !== '') {
        return toPem($inline, $label);
    }
    $path = getenv($pathVar);
    if ($path !== false && trim($path) !== '') {
        if (is_readable($path)) {
            return toPem((string) file_get_contents($path), $label);
        }
        return toPem($path, $label); // not a file — treat as pasted key material
    }
    return null;
}

/** Build a Config from the loaded environment. */
function telebirr_config(): Config
{
    $privateKey = resolveKey('TELEBIRR_PRIVATE_KEY', 'TELEBIRR_PRIVATE_KEY_PATH', 'PRIVATE KEY');
    if ($privateKey === null) {
        fwrite(STDERR, "Set TELEBIRR_PRIVATE_KEY or TELEBIRR_PRIVATE_KEY_PATH.\n");
        exit(1);
    }
    $telebirrPublicKey = resolveKey('TELEBIRR_PUBLIC_KEY', 'TELEBIRR_PUBLIC_KEY_PATH', 'PUBLIC KEY');

    // TLS: verify by default. Set TELEBIRR_VERIFY_SSL=false to disable (dev only),
    // or TELEBIRR_CA_BUNDLE to a cacert.pem to verify against a specific bundle.
    $verifyEnv = strtolower((string) getenv('TELEBIRR_VERIFY_SSL'));
    $verifySsl = !in_array($verifyEnv, ['false', '0', 'no', 'off'], true);
    $caBundle = getenv('TELEBIRR_CA_BUNDLE') ?: null;

    return new Config([
        'environment'       => getenv('TELEBIRR_ENVIRONMENT') ?: 'test',
        'fabricAppId'       => requireEnv('TELEBIRR_FABRIC_APP_ID'),
        'appSecret'         => requireEnv('TELEBIRR_APP_SECRET'),
        'merchantAppId'     => requireEnv('TELEBIRR_MERCHANT_APP_ID'),
        'merchantCode'      => requireEnv('TELEBIRR_MERCHANT_CODE'),
        'privateKey'        => $privateKey,
        'notifyUrl'         => requireEnv('TELEBIRR_NOTIFY_URL'),
        'redirectUrl'       => getenv('TELEBIRR_REDIRECT_URL') ?: null,
        'telebirrPublicKey' => $telebirrPublicKey,
        'verifySsl'         => $verifySsl,
        'caBundlePath'      => $caBundle,
    ]);
}

// Load examples/.env (sits next to this file).
loadEnv(__DIR__ . '/.env');
