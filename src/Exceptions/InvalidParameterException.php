<?php

declare(strict_types=1);

namespace Melaku\Telebirr\Exceptions;

/**
 * Exception thrown when a parameter fails validation
 */
class InvalidParameterException extends \InvalidArgumentException implements TelebirrExceptionInterface
{
    private string $parameterName;
    /** @var mixed */
    private $parameterValue;
    private ?string $suggestion = null;

    /**
     * @param string $parameterName
     * @param mixed $parameterValue
     * @param string $message
     * @param string|null $suggestion
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $parameterName,
        $parameterValue,
        string $message,
        ?string $suggestion = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->parameterName = $parameterName;
        $this->parameterValue = $parameterValue;
        $this->suggestion = $suggestion;
    }

    public function getParameterName(): string
    {
        return $this->parameterName;
    }

    /**
     * @return mixed
     */
    public function getParameterValue()
    {
        return $this->parameterValue;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }
}
