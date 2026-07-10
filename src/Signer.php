<?php

namespace Melaku\Telebirr;

/**
 * Request Signature Handler - Telebirr H5 C2B Request Signature Process
 *
 * Per Telebirr H5 C2B Web Payment Integration Quick Guide (Request_signature_Process):
 * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Request_signature_Process
 *
 * This class implements the signature generation process for Telebirr API requests:
 *
 * Signature Process:
 * 1. Collect all fields from the request object (excluding excluded fields)
 * 2. Flatten fields from biz_content into the main field list
 * 3. Sort all fields alphabetically (ASCII order)
 * 4. Build canonical string: "key1=value1&key2=value2&..."
 * 5. Sign the canonical string using RSA-PSS SHA256 (SHA256withRSAandMGF1)
 * 6. Return base64-encoded signature
 *
 * Excluded Fields (not included in signature):
 * - sign: The signature field itself
 * - sign_type: Signature type field
 * - header: Header information
 * - refund_info: Refund information (legacy)
 * - openType: Open type field
 * - raw_request: Raw request field
 * - biz_content: The biz_content wrapper (but its fields ARE included)
 *
 * Important Notes:
 * - All fields in biz_content MUST participate in the signature
 * - Fields are sorted alphabetically before signing
 * - Uses RSA-PSS padding with SHA256 hash and MGF1
 * - Salt length is set to 32 bytes (hash length for SHA256)
 */
class Signer
{
    /**
     * Fields that are excluded from signature calculation.
     * 
     * Note: While 'biz_content' itself is excluded, all fields WITHIN biz_content
     * are included in the signature (they are flattened into the main field list).
     *
     * @var string[]
     */
    private static array $excludeFields = [
        'sign',
        'sign_type',
        'header',
        'refund_info',
        'openType',
        'raw_request',
        'biz_content',
    ];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Build the canonical string from request object and sign it with RSA-PSS SHA256.
     *
     * Per Telebirr H5 C2B Request Signature Process:
     * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Request_signature_Process
     *
     * Process:
     * 1. Collect all top-level fields (excluding excluded fields)
     * 2. Flatten and collect all fields from biz_content (excluding excluded fields)
     * 3. Sort all collected fields alphabetically
     * 4. Build canonical string: "key1=value1&key2=value2&..."
     * 5. Sign the canonical string using RSA-PSS SHA256
     * 6. Return base64-encoded signature
     *
     * @param array $requestObject The request object to sign (may contain biz_content)
     * @return string Base64-encoded RSA-PSS SHA256 signature
     */
    public function signRequestObject(array $requestObject): string
    {
        $fields   = [];
        $fieldMap = [];

        foreach ($requestObject as $key => $value) {
            if (in_array($key, self::$excludeFields, true)) {
                continue;
            }
            $fields[]       = $key;
            $fieldMap[$key] = $value;
        }

        if (isset($requestObject['biz_content']) && is_array($requestObject['biz_content'])) {
            foreach ($requestObject['biz_content'] as $key => $value) {
                if (in_array($key, self::$excludeFields, true)) {
                    continue;
                }
                $fields[]       = $key;
                $fieldMap[$key] = $value;
            }
        }

        sort($fields, SORT_STRING);

        $parts = [];
        foreach ($fields as $key) {
            $parts[] = $key . '=' . $fieldMap[$key];
        }

        $signOriginStr = implode('&', $parts);

        return $this->signString($signOriginStr);
    }

    /**
     * Sign a string using RSA-PSS SHA256 (SHA256withRSAandMGF1).
     *
     * Per Telebirr H5 C2B Request Signature Process:
     * @see https://developer.ethiotelecom.et/docs/H5%20C2B%20Web%20Payment%20Integration%20Quick%20Guide/Request_signature_Process
     *
     * Signature Algorithm: RSA-PSS with SHA256
     * - Padding: PSS (Probabilistic Signature Scheme)
     * - Hash: SHA256
     * - MGF: MGF1 with SHA256
     * - Salt Length: 32 bytes (equal to hash length)
     *
     * Uses phpseclib (pure-PHP). No OpenSSL CLI required; works on all platforms including Windows.
     *
     * @param string $text The canonical string to sign (e.g., "key1=value1&key2=value2")
     * @return string Base64-encoded signature
     * @throws \RuntimeException if signing fails
     */
    public function signString(string $text): string
    {
        /** @var \phpseclib3\Crypt\RSA\PrivateKey $private */
        $private = \phpseclib3\Crypt\PublicKeyLoader::load($this->config->privateKey);
        $private = $private->withPadding(\phpseclib3\Crypt\RSA::SIGNATURE_PSS)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->withSaltLength(32);

        $signature = $private->sign($text);

        return base64_encode($signature);
    }

    public static function createTimeStamp(): string
    {
        return (string) time();
    }

    public static function createNonceStr(): string
    {
        $chars = [
            '0',
            '1',
            '2',
            '3',
            '4',
            '5',
            '6',
            '7',
            '8',
            '9',
            'A',
            'B',
            'C',
            'D',
            'E',
            'F',
            'G',
            'H',
            'I',
            'J',
            'K',
            'L',
            'M',
            'N',
            'O',
            'P',
            'Q',
            'R',
            'S',
            'T',
            'U',
            'V',
            'W',
            'X',
            'Y',
            'Z',
        ];

        $str = '';
        for ($i = 0; $i < 32; $i++) {
            $index = random_int(0, count($chars) - 1);
            $str .= $chars[$index];
        }
        return $str;
    }
}
