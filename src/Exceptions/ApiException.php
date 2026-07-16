<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Exceptions;

use Melaku\Telebirr\Http\HttpClientException;

/**
 * Thrown when a Telebirr API call fails: transport error, non-2xx HTTP status,
 * a non-JSON body, an API-level error code, or a missing expected field.
 *
 * Carries the HTTP status, the raw response body, AND the parsed fields of
 * Telebirr's error envelope ({errorCode, errorMsg, errorSolution}) via
 * getTelebirrCode()/getTelebirrMessage()/getTelebirrSolution(), so callers can
 * branch on gateway errors programmatically instead of json_decode-ing the
 * response body themselves.
 */
class ApiException extends TelebirrException
{
    /**
     * Telebirr gateway error codes that indicate a TRANSIENT, gateway-side
     * failure — the request was fine, the platform hiccuped, and a retry is
     * the documented remedy (Telebirr's own errorSolution text advises it).
     *
     * '49401024991' — "southbound business service unavailable": the sandbox
     * throws this frequently; it is not an integration bug.
     */
    public const TRANSIENT_TELEBIRR_ERROR_CODES = ['49401024991'];

    /** cURL error numbers that are safe to retry (timeouts, connection drops, DNS blips). */
    private const TRANSIENT_CURL_ERRNOS = [
        6,  // CURLE_COULDNT_RESOLVE_HOST
        7,  // CURLE_COULDNT_CONNECT
        28, // CURLE_OPERATION_TIMEDOUT
        52, // CURLE_GOT_NOTHING
        56, // CURLE_RECV_ERROR
    ];

    private const TRANSIENT_HTTP_STATUSES = [502, 503, 504];

    private ?int $httpStatus;
    private ?string $errorCode;
    private ?string $responseBody;
    private ?string $telebirrCode;
    private ?string $telebirrMessage;
    private ?string $telebirrSolution;

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
        $this->responseBody = $responseBody;

        [$envelopeCode, $envelopeMessage, $envelopeSolution] = self::parseErrorEnvelope($responseBody);
        $this->telebirrCode = $envelopeCode;
        $this->telebirrMessage = $envelopeMessage;
        $this->telebirrSolution = $envelopeSolution;
        // Surface the envelope's code on errorCode too, so it is never null
        // when Telebirr did return one buried in the body.
        $this->errorCode = $errorCode ?? $envelopeCode;
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

    /** Telebirr's `errorCode` (or `code`) from the error envelope in the response body, when present. */
    public function getTelebirrCode(): ?string
    {
        return $this->telebirrCode;
    }

    /** Telebirr's `errorMsg`/`message`/`msg` from the error envelope, when present. */
    public function getTelebirrMessage(): ?string
    {
        return $this->telebirrMessage;
    }

    /** Telebirr's `errorSolution` remediation text from the error envelope, when present. */
    public function getTelebirrSolution(): ?string
    {
        return $this->telebirrSolution;
    }

    /**
     * Whether this failure is known-transient and worth retrying: a Telebirr
     * infra error code (see TRANSIENT_TELEBIRR_ERROR_CODES), an HTTP
     * 502/503/504, or a transport timeout/reset before any response arrived.
     */
    public function isTransient(): bool
    {
        if ($this->telebirrCode !== null && in_array($this->telebirrCode, self::TRANSIENT_TELEBIRR_ERROR_CODES, true)) {
            return true;
        }
        if ($this->httpStatus !== null && in_array($this->httpStatus, self::TRANSIENT_HTTP_STATUSES, true)) {
            return true;
        }
        $previous = $this->getPrevious();
        if ($previous instanceof HttpClientException && in_array((int) $previous->getCode(), self::TRANSIENT_CURL_ERRNOS, true)) {
            return true;
        }
        return false;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [code, message, solution]
     */
    private static function parseErrorEnvelope(?string $responseBody): array
    {
        if ($responseBody === null || $responseBody === '') {
            return [null, null, null];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return [null, null, null];
        }

        $str = static function ($value): ?string {
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            return null;
        };

        $code = $str($decoded['errorCode'] ?? $decoded['code'] ?? null);
        // '00000'/'0' are Telebirr's SUCCESS codes — a body carrying one is not an error envelope.
        if ($code === '00000' || $code === '0') {
            $code = null;
        }

        return [
            $code,
            $str($decoded['errorMsg'] ?? $decoded['message'] ?? $decoded['msg'] ?? null),
            $str($decoded['errorSolution'] ?? null),
        ];
    }
}
