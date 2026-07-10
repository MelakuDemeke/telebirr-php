<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Http;

use Melaku\Telebirr\Exceptions\TelebirrException;

/**
 * Thrown when the transport itself fails (connection error, timeout, DNS, TLS
 * handshake) before any HTTP response is received.
 */
class HttpClientException extends TelebirrException
{
}
