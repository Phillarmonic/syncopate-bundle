<?php

namespace Phillarmonic\SyncopateBundle\Exception;

/**
 * Exception thrown when an integrity constraint violation occurs
 * (e.g., when attempting to insert a duplicate value for a unique field)
 */
class SyncopateIntegrityConstraintException extends SyncopateApiException
{
    private string $field;
    private mixed $value;

    public function __construct(
        string $field,
        mixed $value,
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        ?array $apiResponse = null
    ) {
        $message = $message ?: sprintf(
            'Integrity constraint violation: The value %s for field "%s" already exists and must be unique',
            (is_string($value) || is_numeric($value)) ? '"' . $value . '"' : 'provided',
            $field
        );

        parent::__construct($message, $code, $previous, $apiResponse);
        $this->field = $field;
        $this->value = $value;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Create an exception from an API response
     */
    public static function fromApiResponse(array $apiResponse): self
    {
        $message = $apiResponse['message'] ?? 'Integrity constraint violation';
        $field = $apiResponse['details']['field'] ?? 'unknown';
        $value = $apiResponse['details']['value'] ?? null;
        $code = $apiResponse['code'] ?? 400;

        return new self($field, $value, $message, $code, null, $apiResponse);
    }
}