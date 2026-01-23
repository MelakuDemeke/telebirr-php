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

        // Normalize signature (handle URL encoding issues)
        $normalizedSignature = self::normalizeSignature($signature);

        // Build canonical string (same process as signing)
        $canonicalString = self::buildCanonicalString($params);

        // Try verification with the normalized signature first
        if (self::verifySignature($canonicalString, $normalizedSignature, $telebirrPublicKey)) {
            return true;
        }
        
        // If that fails, try with URL-decoded signature (base64 + becomes space in URLs)
        $urlDecodedSignature = urldecode($signature);
        if ($urlDecodedSignature !== $normalizedSignature && self::verifySignature($canonicalString, $urlDecodedSignature, $telebirrPublicKey)) {
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
        // If signature contains spaces but no +, spaces should be + (base64 uses + not spaces)
        // This happens when PHP converts + to spaces in GET parameters
        if (strpos($signature, ' ') !== false && strpos($signature, '+') === false) {
            $signature = str_replace(' ', '+', $signature);
        }
        
        return $signature;
    }

    /**
     * Decode base64 signature, handling URL encoding issues
     * 
     * @param string $signature The signature string (may be URL-encoded)
     * @return array{decoded: string, attempt: string}|false Returns array with decoded binary and attempt name on success, false on failure
     */
    private static function decodeSignature(string $signature)
    {
        // Try different decoding approaches
        $attempts = [
            // 1. As-is (if already properly formatted)
            ['name' => 'as-is', 'value' => $signature],
            // 2. Replace spaces with + (common URL encoding issue)
            ['name' => 'space-to-plus', 'value' => str_replace(' ', '+', $signature)],
            // 3. URL decode first, then fix spaces
            ['name' => 'url-decode-then-space-fix', 'value' => str_replace(' ', '+', urldecode($signature))],
            // 4. URL decode only
            ['name' => 'url-decode', 'value' => urldecode($signature)],
        ];
        
        foreach ($attempts as $attempt) {
            $attemptValue = $attempt['value'];
            
            // Try to decode
            $decoded = base64_decode($attemptValue, true);
            if ($decoded !== false && strlen($decoded) > 0) {
                return ['decoded' => $decoded, 'attempt' => $attempt['name']];
            }
            
            // If padding might be missing, try adding it
            $paddingNeeded = 4 - (strlen($attemptValue) % 4);
            if ($paddingNeeded !== 4) {
                $attemptWithPadding = $attemptValue . str_repeat('=', $paddingNeeded);
                $decoded = base64_decode($attemptWithPadding, true);
                if ($decoded !== false && strlen($decoded) > 0) {
                    return ['decoded' => $decoded, 'attempt' => $attempt['name'] . ' (with padding)'];
                }
            }
        }
        
        return false;
    }

    /**
     * Verify signature using RSA-PSS SHA256
     * 
     * Uses OpenSSL CLI since PHP's openssl_verify doesn't directly support RSA-PSS
     * 
     * @param string $data The canonical string that was signed
     * @param string $signature Base64-encoded signature (may be URL-encoded)
     * @param string $publicKey Public key in PEM format
     * @return bool True if signature is valid
     * @throws \RuntimeException if verification fails
     */
    private static function verifySignature(string $data, string $signature, string $publicKey): bool
    {
        // Save public key to temporary file
        $tempKeyFile = tempnam(sys_get_temp_dir(), 'tb_pubkey_');
        file_put_contents($tempKeyFile, $publicKey);

        // Save data to temporary file
        $tempDataFile = tempnam(sys_get_temp_dir(), 'tb_data_');
        file_put_contents($tempDataFile, $data);

        // Decode signature using multiple strategies
        $decodeResult = self::decodeSignature($signature);
        
        if ($decodeResult === false) {
            // Enhanced error logging with context
            $errorDetails = [
                'Signature length: ' . strlen($signature),
                'First 50 chars: ' . substr($signature, 0, 50),
                'Canonical string length: ' . strlen($data),
                'Decoding attempts: All failed (as-is, space-to-plus, url-decode-then-space-fix, url-decode)',
            ];
            error_log('SignatureVerifier: Failed to decode base64 signature. ' . implode(', ', $errorDetails));
            @unlink($tempKeyFile);
            @unlink($tempDataFile);
            return false;
        }
        
        $signatureBinary = $decodeResult['decoded'];
        $successfulAttempt = $decodeResult['attempt'];
        
        $tempSigFile = tempnam(sys_get_temp_dir(), 'tb_sig_');
        file_put_contents($tempSigFile, $signatureBinary);

        try {
            // Use OpenSSL CLI to verify RSA-PSS SHA256 signature
            // openssl dgst -sha256 -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:32 
            //        -sigopt rsa_mgf1_md:sha256 -verify pubkey.pem -signature sig.bin data.txt
            $command = sprintf(
                'openssl dgst -sha256 -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:32 -sigopt rsa_mgf1_md:sha256 -verify %s -signature %s %s 2>&1',
                escapeshellarg($tempKeyFile),
                escapeshellarg($tempSigFile),
                escapeshellarg($tempDataFile)
            );

            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);

            // Enhanced error logging with context if verification fails
            if ($returnVar !== 0) {
                $errorDetails = [
                    'Return code: ' . $returnVar,
                    'Signature length: ' . strlen($signature),
                    'First 50 chars: ' . substr($signature, 0, 50),
                    'Canonical string length: ' . strlen($data),
                    'Successful decoding attempt: ' . $successfulAttempt,
                    'Decoded signature size: ' . strlen($signatureBinary) . ' bytes',
                    'OpenSSL output: ' . implode(' ', $output),
                ];
                error_log('SignatureVerifier: OpenSSL verification failed. ' . implode(', ', $errorDetails));
            }

            // OpenSSL returns 0 on success, non-zero on failure
            return $returnVar === 0;

        } finally {
            // Clean up temporary files
            @unlink($tempKeyFile);
            @unlink($tempDataFile);
            @unlink($tempSigFile);
        }
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
        
        // Build canonical string from parsed params
        $canonicalString = self::buildCanonicalString($params);
        
        // Verify signature
        return self::verifySignature($canonicalString, $params['sign'], $telebirrPublicKey);
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
     * @param string $privateKey Private key in PEM format
     * @return string Public key in PEM format
     * @throws \RuntimeException if private key is invalid or extraction fails
     */
    public static function extractPublicKeyFromPrivateKey(string $privateKey): string
    {
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        
        if (!$privateKeyResource) {
            throw new \RuntimeException('Invalid private key format');
        }
        
        $publicKeyDetails = openssl_pkey_get_details($privateKeyResource);
        
        if (!$publicKeyDetails || !isset($publicKeyDetails['key'])) {
            throw new \RuntimeException('Failed to extract public key from private key');
        }
        
        return $publicKeyDetails['key'];
    }
}
