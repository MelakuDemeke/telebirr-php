<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Http;

/**
 * Default cURL-based HTTP client.
 *
 * Unlike the library's original inline cURL calls, this client:
 *  - verifies the server's TLS certificate by default (a payment gateway must
 *    not be talked to over an unverified connection), and
 *  - applies connect and total timeouts so a hung endpoint cannot block the
 *    PHP worker indefinitely.
 *
 * TLS verification can be relaxed and a custom CA bundle supplied for unusual
 * environments, but the safe defaults are on unless you opt out explicitly.
 */
final class CurlHttpClient implements HttpClientInterface
{
    private bool $verifySsl;
    private ?string $caBundlePath;
    private int $timeout;
    private int $connectTimeout;

    /**
     * @param bool        $verifySsl      Verify the peer's TLS certificate (default true).
     * @param string|null $caBundlePath   Path to a CA bundle (PEM) to verify against, if not
     *                                     using the system store.
     * @param int         $timeout        Maximum total request time in seconds (default 30).
     * @param int         $connectTimeout Maximum connection time in seconds (default 10).
     */
    public function __construct(
        bool $verifySsl = true,
        ?string $caBundlePath = null,
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        $this->verifySsl = $verifySsl;
        $this->caBundlePath = $caBundlePath;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public function post(string $url, array $headers, string $body): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new HttpClientException('Failed to initialize cURL for ' . $url);
        }

        $options = [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
        ];

        if ($this->verifySsl && $this->caBundlePath !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundlePath;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $error        = curl_error($ch);
        $errno        = curl_errno($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            throw new HttpClientException(
                'HTTP request to ' . $url . ' failed: ' . ($error !== '' ? $error : 'Unknown cURL error'),
                $errno
            );
        }

        return new HttpResponse($httpCode, (string) $responseBody);
    }
}
