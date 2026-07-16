<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Http;

/**
 * Default cURL-based HTTP client.
 *
 * Unlike the library's original inline cURL calls, this client:
 *  - verifies the server's TLS certificate by default (a payment gateway must
 *    not be talked to over an unverified connection),
 *  - knows about the Telebirr test gateway's incomplete certificate chain:
 *    when system-store verification fails with cURL error 60 and no custom
 *    bundle was supplied, it retries once against the bundled Telebirr CA
 *    chain (src/certs/telebirr-ca.pem) so verification works out of the box —
 *    nobody should ever need verifySsl=false, and
 *  - applies connect and total timeouts so a hung endpoint cannot block the
 *    PHP worker indefinitely.
 *
 * The bundled chain is only consulted after the system store has refused the
 * peer, and it can only validate hosts issued under that exact chain (the
 * Telebirr gateways) — it does not loosen verification for anything else.
 */
final class CurlHttpClient implements HttpClientInterface
{
    /** cURL: "Peer certificate cannot be authenticated with given CA certificates." */
    private const CURLE_PEER_FAILED_VERIFICATION = 60;

    private const TLS_FAILURE_GUIDANCE =
        "\n\nTelebirr's TLS certificate chain failed to verify (even against the bundled Telebirr CA). "
        . 'The gateway certificate may have been rotated. Options: '
        . '1) update this library (refreshed CA bundle); '
        . "2) pass caBundlePath with the gateway's current CA/intermediate PEM; "
        . '3) as a LAST RESORT against the TEST gateway only, set verifySsl=false — never in production.';

    private bool $verifySsl;
    private ?string $caBundlePath;
    private int $timeout;
    private int $connectTimeout;

    /**
     * @param bool        $verifySsl      Verify the peer's TLS certificate (default true).
     * @param string|null $caBundlePath   Path to a CA bundle (PEM) to verify against instead of
     *                                     the system store (disables the bundled-CA fallback).
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

    /** Path of the CA chain shipped with the library. */
    public static function bundledCaPath(): string
    {
        return dirname(__DIR__) . '/certs/telebirr-ca.pem';
    }

    public function post(string $url, array $headers, string $body): HttpResponse
    {
        [$response, $errno, $error] = $this->execute($url, $headers, $body, $this->caBundlePath);
        if ($response !== null) {
            return $response;
        }

        if (
            $this->verifySsl
            && $this->caBundlePath === null
            && $errno === self::CURLE_PEER_FAILED_VERIFICATION
            && is_readable(self::bundledCaPath())
        ) {
            // Known quirk: the test gateway serves an incomplete chain the
            // system store cannot verify. Retry against the bundled chain.
            [$response, $errno, $error] = $this->execute($url, $headers, $body, self::bundledCaPath());
            if ($response !== null) {
                return $response;
            }
        }

        if ($errno === self::CURLE_PEER_FAILED_VERIFICATION) {
            $error .= self::TLS_FAILURE_GUIDANCE;
        }

        throw new HttpClientException(
            'HTTP request to ' . $url . ' failed: ' . ($error !== '' ? $error : 'Unknown cURL error'),
            $errno
        );
    }

    /**
     * Run one cURL attempt.
     *
     * @return array{0: HttpResponse|null, 1: int, 2: string} Response on success, else [null, errno, error].
     * @throws HttpClientException if cURL cannot be initialized.
     */
    private function execute(string $url, array $headers, string $body, ?string $caInfo): array
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

        if ($this->verifySsl && $caInfo !== null) {
            $options[CURLOPT_CAINFO] = $caInfo;
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $error        = curl_error($ch);
        $errno        = curl_errno($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            return [null, $errno, $error];
        }

        return [new HttpResponse($httpCode, (string) $responseBody), 0, ''];
    }
}
