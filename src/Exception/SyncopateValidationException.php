<?php

namespace Phillarmonic\SyncopateBundle\Exception;

class SyncopateValidationException extends \InvalidArgumentException
{
    private array $violations = [];

    public function __construct(
        string $message = "",
        array $violations = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->violations = $violations;
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    public function addViolation(string $field, string $message): self
    {
        $this->violations[$field] = $message;
        return $this;
    }

    public static function create(string $message = "Validation failed"): self
    {
        return new self($message);
    }
}