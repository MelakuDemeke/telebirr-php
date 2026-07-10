<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Exceptions;

/**
 * Base runtime exception for the Telebirr library.
 *
 * All non-argument errors thrown by the library extend this class, so callers
 * can catch {@see TelebirrExceptionInterface} (or this class) to handle them
 * uniformly.
 */
class TelebirrException extends \RuntimeException implements TelebirrExceptionInterface
{
}
