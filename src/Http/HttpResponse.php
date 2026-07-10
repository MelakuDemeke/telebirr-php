<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Http;

/**
 * A minimal HTTP response value object returned by {@see HttpClientInterface}.
 */
final class HttpResponse
{
    private int $statusCode;
    private string $body;

    public function __construct(int $statusCode, string $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** True for a 2xx status code. */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
