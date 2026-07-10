<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Logger;

use Psr\Log\NullLogger as PsrNullLogger;

/**
 * No-op logger used as the default when none is injected.
 *
 * Kept for backward compatibility; it now simply extends {@see \Psr\Log\NullLogger}.
 * New code can inject any PSR-3 logger (Monolog, Laravel's, etc.) directly.
 */
class NullLogger extends PsrNullLogger
{
}
