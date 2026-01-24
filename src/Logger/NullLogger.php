<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Logger;

/**
 * Null logger - does nothing (no-op)
 * Used as default when no logger is provided
 */
class NullLogger implements LoggerInterface
{
    public function log(string $level, string $message, array $context = []): void
    {
        // No-op
    }

    public function debug(string $message, array $context = []): void
    {
        // No-op
    }

    public function info(string $message, array $context = []): void
    {
        // No-op
    }

    public function warning(string $message, array $context = []): void
    {
        // No-op
    }

    public function error(string $message, array $context = []): void
    {
        // No-op
    }
}
