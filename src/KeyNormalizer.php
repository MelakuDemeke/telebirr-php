<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

/**
 * Normalizes RSA key material into PEM before it reaches the crypto layer.
 *
 * Ethio Telecom issues merchant keys as **bare base64 DER** (no PEM armor, no
 * line breaks). These helpers accept either form — bare base64 or PEM
 * (including PEM whose newlines were flattened to literal `\n` by an env
 * file) — and always return proper PEM, picking the right header
 * (`PRIVATE KEY` PKCS#8 vs `RSA PRIVATE KEY` PKCS#1; `PUBLIC KEY` SPKI vs
 * `RSA PUBLIC KEY` PKCS#1) by test-parsing the candidates with OpenSSL.
 *
 * This is the same logic the examples' bootstrap used to carry — moved into
 * the library so every integrator gets it, not only those who copy the demo.
 */
final class KeyNormalizer
{
    /** Normalize a private key (bare base64 DER or PEM) to PEM. */
    public static function normalizePrivateKey(string $key): string
    {
        return self::normalize(
            $key,
            ['PRIVATE KEY', 'RSA PRIVATE KEY'],
            static function (string $pem): bool {
                return @openssl_pkey_get_private($pem) !== false;
            }
        );
    }

    /** Normalize a public key (bare base64 DER or PEM) to PEM. */
    public static function normalizePublicKey(string $key): string
    {
        return self::normalize(
            $key,
            ['PUBLIC KEY', 'RSA PUBLIC KEY'],
            static function (string $pem): bool {
                return @openssl_pkey_get_public($pem) !== false;
            }
        );
    }

    /**
     * @param string[] $labels PEM labels to try, most likely first.
     * @param callable(string):bool $parses Whether OpenSSL can parse the candidate PEM.
     */
    private static function normalize(string $key, array $labels, callable $parses): string
    {
        $trimmed = trim($key);

        // Already PEM — just repair literal `\n` sequences from env files.
        if (strpos($trimmed, '-----BEGIN') !== false) {
            return str_replace('\n', "\n", $trimmed);
        }

        $body = preg_replace('/\s+/', '', str_replace('\n', '', $trimmed));
        if ($body === null || $body === '' || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $body)) {
            return $key; // not base64 — leave untouched so validation reports it
        }

        $candidates = [];
        foreach ($labels as $label) {
            $candidates[] = "-----BEGIN {$label}-----\n" . chunk_split($body, 64, "\n") . "-----END {$label}-----\n";
        }

        foreach ($candidates as $pem) {
            if ($parses($pem)) {
                return $pem;
            }
        }

        // Unparseable either way: return the conventional wrapping so the later
        // crypto error at least shows a well-formed PEM was attempted.
        return $candidates[0];
    }
}
