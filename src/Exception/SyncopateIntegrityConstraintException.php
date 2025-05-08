<?php

namespace Phillarmonic\SyncopateBundle\Exception;

/**
 * Exception thrown when an integrity constraint violation occurs
 * (e.g., when attempting to insert a duplicate value for a unique field)
 */
class SyncopateIntegrityConstraintException extends SyncopateApiException
{
    /**
     * The field that violated the constraint
     */
    private string $field;

    /**
     * The value that caused the violation
     */
    private mixed $value;

    /**
     * Create a new integrity constraint exception
     *
     * @param string $field The field that violated the constraint
     * @param mixed $value The value that caused the violation
     * @param string $message The exception message
     * @param int $code The HTTP status code
     * @param \Throwable|null $previous The previous throwable
     * @param array|null $apiResponse The original API response
     */
    public function __construct(
        string $field,
        mixed $value,
        string $message = "",
        int $code = 409,
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

    /**
     * Get the field that violated the constraint
     *
     * @return string
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Get the value that caused the violation
     *
     * @return mixed
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Create an exception from an API response
     *
     * @param array $apiResponse The API response data
     * @return self
     */
    public static function fromApiResponse(array $apiResponse): self
    {
        $message = $apiResponse['message'] ?? 'Integrity constraint violation';
        $field = 'unknown';
        $value = null;
        $code = $apiResponse['code'] ?? 409;
        $dbCode = $apiResponse['db_code'] ?? null;

        // Try different formats for extracting field and value information

        // Format 1: Standard details structure
        if (isset($apiResponse['details'])) {
            if (isset($apiResponse['details']['field'])) {
                $field = $apiResponse['details']['field'];
            }
            if (isset($apiResponse['details']['value'])) {
                $value = $apiResponse['details']['value'];
            }
            // Alternative format with constraint info
            elseif (isset($apiResponse['details']['constraint'])) {
                if (isset($apiResponse['details']['constraint']['field'])) {
                    $field = $apiResponse['details']['constraint']['field'];
                }
                if (isset($apiResponse['details']['constraint']['value'])) {
                    $value = $apiResponse['details']['constraint']['value'];
                }
            }
        }

        // Format 2: Extract from error message using regex
        if ($field === 'unknown' || $value === null) {
            // Pattern for "field 'name' with value 'value'"
            if (preg_match('/field [\'"]([^\'"]*)[\'"]\s+with\s+value\s+[\'"]([^\'"]*)[\'"]/', $message, $matches)) {
                $field = $matches[1];
                $value = $matches[2];
            }
            // Pattern for "duplicate entry 'value' for key 'name'" (MySQL style)
            elseif (preg_match('/duplicate entry [\'"]([^\'"]*)[\'"]\s+for\s+key\s+[\'"]([^\'"]*)[\'"]/', $message, $matches)) {
                $value = $matches[1];
                $field = $matches[2];
            }
            // Pattern for "unique constraint on column 'name'"
            elseif (preg_match('/unique constraint on (?:column|field) [\'"]([^\'"]*)[\'"]/', $message, $matches)) {
                $field = $matches[1];
            }
        }

        // Log the dbCode in parent class
        $apiResponse['db_code'] = $dbCode ?? 'SY209'; // Default to unique constraint code if missing

        return new self($field, $value, $message, $code, null, $apiResponse);
    }

    /**
     * Check if the violation is for a specific field
     *
     * @param string $fieldName The field name to check
     * @return bool
     */
    public function isFieldViolation(string $fieldName): bool
    {
        return $this->field === $fieldName;
    }

    /**
     * Get a user-friendly error message
     *
     * @return string
     */
    public function getFriendlyMessage(): string
    {
        if (is_string($this->value) || is_numeric($this->value)) {
            return sprintf(
                'The %s "%s" is already in use. Please choose a different value.',
                $this->field,
                $this->value
            );
        }

        return sprintf('The provided %s is already in use. Please choose a different value.', $this->field);
    }
}