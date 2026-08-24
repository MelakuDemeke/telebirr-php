<?php

namespace Melaku\Telebirr;

/**
 * Telebirr Signature Verifier
 * 
 * This class verifies signatures from Telebirr's return URLs and notifications.
 * 
 * IMPORTANT: You need a PUBLIC KEY to verify signatures.
 * 
 * If Telebirr signs using YOUR private key, you can extract your public key
 * from your private key using extractPublicKeyFromPrivateKey().
 * 
 * If Telebirr has their own key pair, you need Telebirr's public key
 * (contact Telebirr support to obtain it).
 * 
 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Request_signature_Process
 */
class SignatureVerifier
{
    /**
     * Fields excluded from signature calculation
     */
    private static array $excludeFields = [
        'sign',
        'sign_type',
    ];

    /**
     * Fields Telebirr signs under one name and transmits under another.
     *
     * Keyed by the name that arrives on the wire, valued by the name that went
     * into the hash. Telebirr's own integration guide names the transaction id
     * `trans_id` in its sample callback; the JSON their gateway actually POSTs
     * calls it `transId`. They sign the former and send the latter, so a
     * canonical string built from the keys as received can never match theirs,
     * and every notification carrying that field is refused.
     *
     * The rename breaks verification twice over, which is why no reordering of
     * the received keys can rescue it: `transId` sorts *before* `trans_currency`
     * (`I` is 0x49, `_` is 0x5F) while `trans_id` sorts *after* `trans_end_time`.
     * Wrong name and wrong position from a single substitution.
     *
     * Confirmed against production merchant 500289 on 2026-08-21: the notify for
     * `AFROTESTHGSCFT8BPU8YMB` fails all 8,188 combinations of the received keys
     * -- every non-empty subset of the 11 fields, times four orderings, times
     * PSS and PKCS#1 -- and verifies on the first attempt once renamed. The same
     * payment's return leg verifies untouched, because the return carries no
     * transaction id at all; that is why this only ever broke the notify leg,
     * and why it survived a merchant being issued the correct public key.
     *
     * @var array<string, string>
     */
    private static array $signedFieldAliases = [
        'transId' => 'trans_id',
    ];

    /**
     * Verify Telebirr signature from return URL or notification
     * 
     * @param array $params All parameters (including sign and sign_type)
     * @param Config|string $configOrPublicKey Config instance or Telebirr's public key in PEM format
     * @return bool True if signature is valid, false otherwise
     * @throws \RuntimeException if public key is invalid or verification fails
     */
    public static function verify(array $params, $configOrPublicKey): bool
    {
        // Get public key from Config or use provided string
        $telebirrPublicKey = self::getPublicKey($configOrPublicKey);

        if (!$telebirrPublicKey) {
            throw new \RuntimeException('No public key available for verification. Provide telebirrPublicKey in config or pass it directly.');
        }

        $signature = $params['sign'] ?? '';
        $signType = $params['sign_type'] ?? '';

        if (empty($signature) || empty($signType)) {
            return false;
        }

        // Check for signature truncation (common issue with long URLs)
        if (self::detectTruncation($signature)) {
            error_log('SignatureVerifier: Signature appears truncated. Length: ' . strlen($signature) . ', Expected ~512 characters. The URL might be too long.');
        }

        // Every canonical string Telebirr might have hashed, against every
        // reading of the signature bytes. verifySignature() handles the
        // encoding permutations; canonicalStringVariants() handles the field
        // naming ones.
        if (self::verifyParams($params, (string) $signature, $telebirrPublicKey)) {
            return true;
        }

        // Also try using raw query string if available (as fallback)
        if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
            if (self::verifyFromRawQueryString($_SERVER['QUERY_STRING'], $telebirrPublicKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify one parameter set against every canonical string Telebirr might have signed.
     *
     * @param array $params All parameters (including sign and sign_type)
     * @param string $signature The signature as received, before any normalization
     * @param string $publicKey Telebirr's public key in PEM format
     */
    private static function verifyParams(array $params, string $signature, string $publicKey): bool
    {
        foreach (self::canonicalStringVariants($params) as $canonicalString) {
            if (self::verifySignature($canonicalString, $signature, $publicKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The canonical strings Telebirr might have hashed for this payload.
     *
     * The payload exactly as received comes first, so a gateway that names its
     * fields consistently costs nothing and nothing here has to change the day
     * Telebirr aligns the two spellings. Only then is the aliased form tried.
     *
     * This widens which *string* is hashed. It never widens *who* may have
     * signed it -- every variant is checked against the same public key, so
     * forging any of them still requires Telebirr's private key.
     *
     * @param array $params All parameters (including sign and sign_type)
     * @return array<int, string>
     */
    private static function canonicalStringVariants(array $params): array
    {
        $variants = [self::buildCanonicalString($params)];

        $aliased = $params;
        $renamed = false;

        foreach (self::$signedFieldAliases as $sentAs => $signedAs) {
            if (array_key_exists($sentAs, $aliased) && !array_key_exists($signedAs, $aliased)) {
                $aliased[$signedAs] = $aliased[$sentAs];
                unset($aliased[$sentAs]);
                $renamed = true;
            }
        }

        if ($renamed) {
            $variants[] = self::buildCanonicalString($aliased);
        }

        return $variants;
    }

    /**
     * Get public key from Config or string
     * 
     * @param Config|string $configOrPublicKey Config instance or public key string
     * @return string|null Public key in PEM format, or null if not available
     */
    private static function getPublicKey($configOrPublicKey): ?string
    {
        if ($configOrPublicKey instanceof Config) {
            // Try Telebirr's public key first
            if (!empty($configOrPublicKey->telebirrPublicKey)) {
                return $configOrPublicKey->telebirrPublicKey;
            }

            // Fall back to extracting from private key
            if (!empty($configOrPublicKey->privateKey)) {
                try {
                    return self::extractPublicKeyFromPrivateKey($configOrPublicKey->privateKey);
                } catch (\Exception $e) {
                    // Ignore and return null
                }
            }

            return null;
        }

        // Assume it's a public key string
        return is_string($configOrPublicKey) ? $configOrPublicKey : null;
    }

    /**
     * Build canonical string from parameters (same process as signing)
     * 
     * @param array $params All parameters
     * @return string Canonical string: "key1=value1&key2=value2&..."
     */
    private static function buildCanonicalString(array $params): string
    {
        $fields = [];
        $fieldMap = [];

        // Collect all fields except excluded ones
        foreach ($params as $key => $value) {
            if (in_array($key, self::$excludeFields, true)) {
                continue;
            }
            $fields[] = $key;
            $fieldMap[$key] = $value;
        }

        // Sort fields alphabetically
        sort($fields, SORT_STRING);

        // Build canonical string
        $parts = [];
        foreach ($fields as $key) {
            $parts[] = $key . '=' . $fieldMap[$key];
        }

        return implode('&', $parts);
    }

    /**
     * Detect if signature appears to be truncated
     * 
     * Telebirr signatures are typically ~512 characters when base64-encoded.
     * Signatures shorter than 400 characters are likely truncated, which can
     * happen when URLs are too long and get cut off.
     * 
     * @param string $signature The signature string to check
     * @return bool True if signature appears truncated, false otherwise
     */
    public static function detectTruncation(string $signature): bool
    {
        // Typical base64-encoded RSA signatures are ~512 characters
        // Signatures shorter than 400 characters are suspicious
        return strlen($signature) < 400;
    }

    /**
     * Normalize signature string for verification
     * 
     * Handles common URL encoding issues:
     * - Converts spaces to + (base64 uses + not spaces)
     * - Handles URL encoding edge cases
     * 
     * This is a public method that can be used for pre-processing signatures
     * before verification, or for diagnostic purposes.
     * 
     * @param string $signature The signature string (may be URL-encoded)
     * @return string Normalized signature string
     */
    public static function normalizeSignature(string $signature): string
    {
        // The base64 alphabet contains no space, so a space in a signature is
        // always a `+` that URL decoding ate: form-urlencoded maps `+` to space,
        // and Telebirr sends the raw `+` unencoded in the return URL's query
        // string rather than as `%2B`.
        //
        // This used to substitute only when the signature contained no `+` at
        // all, which gave up on the one case that actually needs help -- a
        // partially encoded signature carrying both a literal `+` (from `%2B`)
        // and a mangled space. Substituting unconditionally is safe precisely
        // because a space can never be legitimate base64 content.
        if (strpos($signature, ' ') !== false) {
            $signature = str_replace(' ', '+', $signature);
        }

        return $signature;
    }

    /**
     * Every plausible reading of a base64 signature, as raw binary.
     *
     * Returns *all* readings rather than the first that decodes, because in PHP
     * "decodes" is not the same as "decodes correctly". `base64_decode()` skips
     * whitespace even in strict mode, so a signature whose `+` characters were
     * turned into spaces still decodes happily -- to shorter, wrong bytes, with
     * no error to notice. Returning that first reading meant the correct
     * space-to-plus candidate was never reached, and the caller saw an
     * indistinguishable "invalid signature".
     *
     * Verified on PHP: `base64_decode('QQ  ==', true)` returns a string, not
     * false. A 512-character mangled signature decodes to 379 bytes where the
     * repaired one gives 384.
     *
     * @param string $signature The signature string (may be URL-encoded or mangled)
     * @return array<int, string> Distinct decoded binaries, most likely first
     */
    private static function decodeSignatureCandidates(string $signature): array
    {
        $attempts = [
            // 1. Repaired first: a space is always a mangled `+` (see normalizeSignature)
            str_replace(' ', '+', $signature),
            // 2. As-is (if already properly formatted)
            $signature,
            // 3. URL decode first, then fix spaces
            str_replace(' ', '+', urldecode($signature)),
            // 4. URL decode only
            urldecode($signature),
        ];

        $decoded = [];

        foreach ($attempts as $attempt) {
            $candidates = [$attempt];

            // Padding is sometimes lost in transit; try restoring it too.
            $paddingNeeded = 4 - (strlen($attempt) % 4);
            if ($paddingNeeded !== 4) {
                $candidates[] = $attempt . str_repeat('=', $paddingNeeded);
            }

            foreach ($candidates as $candidate) {
                $binary = base64_decode($candidate, true);

                if ($binary !== false && $binary !== '' && !in_array($binary, $decoded, true)) {
                    $decoded[] = $binary;
                }
            }
        }

        return $decoded;
    }

    /**
     * Verify signature using RSA-PSS SHA256.
     *
     * Uses phpseclib (pure-PHP). No OpenSSL CLI required; works on all platforms including Windows.
     *
     * @param string $data The canonical string that was signed
     * @param string $signature Base64-encoded signature (may be URL-encoded)
     * @param string $publicKey Public key in PEM format
     * @return bool True if signature is valid
     */
    private static function verifySignature(string $data, string $signature, string $publicKey): bool
    {
        $candidates = self::decodeSignatureCandidates($signature);

        if ($candidates === []) {
            $errorDetails = [
                'Signature length: ' . strlen($signature),
                'First 50 chars: ' . substr($signature, 0, 50),
                'Canonical string length: ' . strlen($data),
                'Decoding attempts: All failed (space-to-plus, as-is, url-decode-then-space-fix, url-decode)',
            ];
            error_log('SignatureVerifier: Failed to decode base64 signature. ' . implode(', ', $errorDetails));
            return false;
        }

        /** @var \phpseclib3\Crypt\RSA\PublicKey $pub */
        $pub = \phpseclib3\Crypt\PublicKeyLoader::load($publicKey);
        $pub = $pub->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PSS)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->withSaltLength(32);

        // Every reading is checked against the same key, so trying several
        // widens which bytes we are willing to call the signature, never who
        // is allowed to have produced them.
        foreach ($candidates as $signatureBinary) {
            if ($pub->verify($data, $signatureBinary)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify signature using raw query string (alternative method)
     * 
     * Sometimes Telebirr signs the raw query string before URL encoding.
     * This method tries to verify using the raw query string parameters.
     * 
     * @param string $rawQueryString The raw QUERY_STRING from $_SERVER
     * @param Config|string $configOrPublicKey Config instance or Telebirr's public key in PEM format
     * @return bool True if signature is valid
     */
    public static function verifyFromRawQueryString(string $rawQueryString, $configOrPublicKey): bool
    {
        $telebirrPublicKey = self::getPublicKey($configOrPublicKey);

        if (!$telebirrPublicKey) {
            return false;
        }

        // Parse the raw query string
        parse_str($rawQueryString, $params);

        if (empty($params['sign']) || empty($params['sign_type'])) {
            return false;
        }

        return self::verifyParams($params, (string) $params['sign'], $telebirrPublicKey);
    }

    /**
     * Get canonical string for debugging
     * 
     * @param array $params All parameters
     * @return string Canonical string that would be signed
     */
    public static function getCanonicalString(array $params): string
    {
        return self::buildCanonicalString($params);
    }

    /**
     * Extract public key from private key
     *
     * If Telebirr signs using your private key, you can extract your public key
     * from your private key and use it to verify their signatures.
     *
     * Uses phpseclib (pure-PHP). No OpenSSL extension required.
     *
     * @param string $privateKey Private key in PEM format
     * @return string Public key in PEM format (PKCS8 / SPKI)
     * @throws \RuntimeException if private key is invalid or extraction fails
     */
    public static function extractPublicKeyFromPrivateKey(string $privateKey): string
    {
        try {
            /** @var \phpseclib3\Crypt\RSA\PrivateKey $private */
            $private = \phpseclib3\Crypt\PublicKeyLoader::load($privateKey);
            $pub = $private->getPublicKey();
            return $pub->toString('PKCS8');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Invalid private key or failed to extract public key: ' . $e->getMessage());
        }
    }
}
