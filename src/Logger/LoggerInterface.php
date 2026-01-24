<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Logger;

/**
 * Simple logger interface compatible with PSR-3
 * 
 * If psr/log is available, this will be an alias to Psr\Log\LoggerInterface
 * Otherwise, provides a simple interface for basic logging
 */
interface LoggerInterface
{
    /**
     * Log a message with a given level
     * 
     * @param string $level Log level (debug, info, warning, error)
     * @param string $message Message to log
     * @param array $context Additional context data
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void;

    /**
     * Log a debug message
     * 
     * @param string $message Message to log
     * @param array $context Additional context data
     * @return void
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Log an info message
     * 
     * @param string $message Message to log
     * @param array $context Additional context data
     * @return void
     */
    public function info(string $message, array $context = []): void;

    /**
     * Log a warning message
     * 
     * @param string $message Message to log
     * @param array $context Additional context data
     * @return void
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Log an error message
     * 
     * @param string $message Message to log
     * @param array $context Additional context data
     * @return void
     */
    public function error(string $message, array $context = []): void;
}
