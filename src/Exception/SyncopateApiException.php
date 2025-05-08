<?php

namespace Phillarmonic\SyncopateBundle\Exception;

/**
 * Base exception for all SyncopateDB API errors
 */
class SyncopateApiException extends \RuntimeException
{
    /**
     * The original API response data
     */
    private ?array $apiResponse = null;

    /**
     * The SyncopateDB error code (e.g., SY200)
     */
    private ?string $dbCode = null;

    /**
     * Create a new SyncopateDB API exception
     *
     * @param string $message The exception message
     * @param int $code The HTTP status code
     * @param \Throwable|null $previous The previous throwable
     * @param array|null $apiResponse The original API response
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        ?array $apiResponse = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->apiResponse = $apiResponse;

        // Extract DB code from response if available
        if ($apiResponse !== null && isset($apiResponse['db_code'])) {
            $this->dbCode = $apiResponse['db_code'];
        }
    }

    /**
     * Get the original API response
     *
     * @return array|null
     */
    public function getApiResponse(): ?array
    {
        return $this->apiResponse;
    }

    /**
     * Get the SyncopateDB error code
     *
     * @return string|null
     */
    public function getDbCode(): ?string
    {
        return $this->dbCode;
    }

    /**
     * Check if this is a client error (4xx)
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        $code = $this->getCode();
        return $code >= 400 && $code < 500;
    }

    /**
     * Check if this is a server error (5xx)
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        $code = $this->getCode();
        return $code >= 500;
    }

    /**
     * Check if this is a specific type of error by DB code
     *
     * @param string $dbCode The DB code to check
     * @return bool
     */
    public function isErrorType(string $dbCode): bool
    {
        return $this->dbCode === $dbCode;
    }

    /**
     * Check if this is a "not found" error
     *
     * @return bool
     */
    public function isNotFoundError(): bool
    {
        return $this->getCode() === 404 || in_array($this->dbCode, ['SY100', 'SY200']);
    }

    /**
     * Get the error category based on DB code
     *
     * @return string The error category (General, Entity Type, Entity, Query, Persistence)
     */
    public function getErrorCategory(): string
    {
        if (!$this->dbCode) {
            return 'Unknown';
        }

        $prefix = substr($this->dbCode, 0, 3);
        return match ($prefix) {
            'SY0' => 'General',
            'SY1' => 'Entity Type',
            'SY2' => 'Entity',
            'SY3' => 'Query',
            'SY4' => 'Persistence',
            default => 'Unknown',
        };
    }

    /**
     * Create an exception from an API response
     *
     * @param array $apiResponse The API response data
     * @return SyncopateApiException Always returns a SyncopateApiException or a subclass instance
     */
    public static function fromApiResponse(array $apiResponse): SyncopateApiException
    {
        $message = $apiResponse['message'] ?? 'Unknown API error';
        $code = $apiResponse['code'] ?? 500;
        $dbCode = $apiResponse['db_code'] ?? null;

        // For any error, return a general API exception
        return new static($message, $code, null, $apiResponse);
    }
}