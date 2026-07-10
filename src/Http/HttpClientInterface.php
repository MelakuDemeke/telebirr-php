<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Http;

/**
 * Small HTTP client abstraction the library uses for every Telebirr API call.
 *
 * Injecting an implementation is what makes the library unit-testable: pass a
 * fake that returns canned {@see HttpResponse} objects and no network is
 * touched. The default {@see CurlHttpClient} performs real requests with TLS
 * verification and timeouts enabled.
 */
interface HttpClientInterface
{
    /**
     * Perform a POST request.
     *
     * @param string   $url     Absolute request URL.
     * @param string[] $headers Full header lines, e.g. ['Content-Type: application/json'].
     * @param string   $body    Raw request body.
     * @return HttpResponse The status code and body. Implementations MUST NOT
     *                       throw on a non-2xx status — only on transport failure.
     * @throws HttpClientException on connection/timeout/TLS errors.
     */
    public function post(string $url, array $headers, string $body): HttpResponse;
}
