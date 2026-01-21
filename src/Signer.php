<?php

namespace Melaku\Telebirr;

class Signer
{
    /** @var string[] */
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
     * RSA-PSS SHA256 (SHA256withRSAandMGF1) using openssl CLI, returns base64 signature.
     */
    public function signString(string $text): string
    {
        $tempKeyFile  = tempnam(sys_get_temp_dir(), 'tb_key_');
        $tempTextFile = tempnam(sys_get_temp_dir(), 'tb_text_');

        file_put_contents($tempKeyFile, $this->config->privateKey);
        file_put_contents($tempTextFile, $text);

        try {
            $tempSigFile = tempnam(sys_get_temp_dir(), 'tb_sig_');

            $command = sprintf(
                'openssl dgst -sha256 -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:32 -sigopt rsa_mgf1_md:sha256 -sign %s -out %s %s 2>&1',
                escapeshellarg($tempKeyFile),
                escapeshellarg($tempSigFile),
                escapeshellarg($tempTextFile)
            );

            $output    = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);

            if ($returnVar !== 0 || !file_exists($tempSigFile)) {
                $errorMsg = implode("\n", $output);
                throw new \RuntimeException('OpenSSL signing failed: ' . $errorMsg);
            }

            $signature = file_get_contents($tempSigFile);

            return base64_encode($signature);
        } finally {
            @unlink($tempKeyFile);
            @unlink($tempTextFile);
            if (isset($tempSigFile)) {
                @unlink($tempSigFile);
            }
        }
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
