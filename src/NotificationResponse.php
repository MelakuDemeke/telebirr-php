<?php

declare(strict_types=1);

namespace Melaku\Telebirr;

/**
 * An acknowledgement to return to Telebirr from your notification endpoint.
 *
 * This is a plain value object — it does NOT touch `header()` or `echo`. In a
 * framework, read {@see getStatusCode()}, {@see getHeaders()} and
 * {@see getBody()} and build your own Response so the framework's response
 * lifecycle stays intact. Only in a bare PHP script (no framework) should you
 * call {@see send()}, which emits headers and the body directly.
 */
final class NotificationResponse
{
    private int $statusCode;
    private string $body;
    /** @var array<string,string> */
    private array $headers;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers + ['Content-Type' => 'application/json'];
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Emit this response directly (headers + body) for bare-PHP endpoints.
     *
     * Do not use inside a framework — return a proper Response there instead.
     * No-op on headers if they were already sent.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
