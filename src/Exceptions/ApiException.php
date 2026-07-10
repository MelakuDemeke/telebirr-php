<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Exceptions;

/**
 * Thrown when a Telebirr API call fails: transport error, non-2xx HTTP status,
 * a non-JSON body, an API-level error code, or a missing expected field.
 *
 * Carries the HTTP status code, the Telebirr error code, and the raw response
 * body (when available) so callers can branch on them programmatically instead
 * of parsing the message string.
 */
class ApiException extends TelebirrException
{
    private ?int $httpStatus;
    private ?string $errorCode;
    private ?string $responseBody;

    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $responseBody = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->responseBody = $responseBody;
    }

    /** HTTP status code returned by Telebirr, if the request reached the server. */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** Telebirr API-level error code (e.g. "60320025"), if the body carried one. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** Raw response body, if any was received. */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
