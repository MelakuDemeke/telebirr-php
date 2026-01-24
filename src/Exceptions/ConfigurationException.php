<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Exceptions;

/**
 * Exception thrown when configuration validation fails
 */
class ConfigurationException extends \InvalidArgumentException
{
    private array $errors;

    public function __construct(
        array $errors,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if (empty($message)) {
            $message = "Configuration validation failed:\n" . implode("\n", $errors);
        }
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
