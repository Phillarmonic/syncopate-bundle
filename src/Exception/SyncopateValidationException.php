<?php

namespace Phillarmonic\SyncopateBundle\Exception;

/**
 * Exception thrown when entity validation fails
 */
class SyncopateValidationException extends \InvalidArgumentException
{
    /**
     * Array of validation violations with field names as keys and error messages as values
     */
    private array $violations = [];

    /**
     * Create a new validation exception
     *
     * @param string $message The exception message
     * @param array $violations Initial validation violations
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable
     */
    public function __construct(
        string $message = "Validation failed",
        array $violations = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->violations = $violations;
    }

    /**
     * Get all validation violations
     *
     * @return array Associative array of field => message
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * Add a validation violation
     *
     * @param string $field The field name
     * @param string $message The error message
     * @return self
     */
    public function addViolation(string $field, string $message): self
    {
        $this->violations[$field] = $message;
        return $this;
    }

    /**
     * Check if there are any violations for a specific field
     *
     * @param string $field The field name
     * @return bool
     */
    public function hasViolation(string $field): bool
    {
        return isset($this->violations[$field]);
    }

    /**
     * Get the validation message for a specific field
     *
     * @param string $field The field name
     * @return string|null The validation message or null if not found
     */
    public function getViolation(string $field): ?string
    {
        return $this->violations[$field] ?? null;
    }

    /**
     * Check if there are any violations
     *
     * @return bool
     */
    public function hasViolations(): bool
    {
        return !empty($this->violations);
    }

    /**
     * Factory method to create a new validation exception
     *
     * @param string $message The exception message
     * @return self
     */
    public static function create(string $message = "Validation failed"): self
    {
        return new self($message);
    }

    /**
     * Create a validation exception from API response data
     *
     * @param array $responseData The API response data
     * @param string $defaultMessage Default message if none is provided
     * @return self
     */
    public static function createFromApiResponse(array $responseData, string $defaultMessage = "Validation failed"): self
    {
        $message = $responseData['message'] ?? $defaultMessage;
        $code = $responseData['code'] ?? 400;
        $violations = [];

        // Try to extract field violations from response details
        if (isset($responseData['details'])) {
            // Format 1: Using 'fields' object in details
            if (isset($responseData['details']['fields']) && is_array($responseData['details']['fields'])) {
                foreach ($responseData['details']['fields'] as $field => $fieldError) {
                    $violations[$field] = is_string($fieldError) ? $fieldError : json_encode($fieldError);
                }
            }
            // Format 2: Using 'violations' array in details
            elseif (isset($responseData['details']['violations']) && is_array($responseData['details']['violations'])) {
                foreach ($responseData['details']['violations'] as $violation) {
                    if (isset($violation['field']) && isset($violation['message'])) {
                        $violations[$violation['field']] = $violation['message'];
                    }
                }
            }
            // Format 3: Single field error
            elseif (isset($responseData['details']['field']) && isset($responseData['details']['message'])) {
                $violations[$responseData['details']['field']] = $responseData['details']['message'];
            }
        }

        // If no violations found in details, try to extract from error message
        if (empty($violations)) {
            // Look for "field 'name' [message]" pattern
            if (preg_match('/field [\'"]([^\'"]*)[\'"]\s+(.+)/i', $message, $matches)) {
                $field = $matches[1];
                $fieldMessage = $matches[2];
                $violations[$field] = $fieldMessage;
            }
            // Look for "required field 'name'" pattern
            elseif (preg_match('/required field [\'"]([^\'"]*)[\'"]/', $message, $matches)) {
                $field = $matches[1];
                $violations[$field] = "Field is required";
            }
            // Look for "invalid field 'name'" pattern
            elseif (preg_match('/invalid field [\'"]([^\'"]*)[\'"]/', $message, $matches)) {
                $field = $matches[1];
                $violations[$field] = "Field is invalid";
            }
        }

        return new self($message, $violations, $code);
    }
}