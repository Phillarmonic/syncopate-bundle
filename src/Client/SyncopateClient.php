<?php

namespace Phillarmonic\SyncopateBundle\Client;

use Phillarmonic\SyncopateBundle\Exception\SyncopateApiException;
use Phillarmonic\SyncopateBundle\Util\DebugHelper;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SyncopateClient
{
    private HttpClientInterface $httpClient;
    private string $baseUrl;
    private array $defaultOptions;

    // Chunk size for processing responses
    private const RESPONSE_CHUNK_SIZE = 1024 * 512; // 512KB chunks

    /**
     * Flag to enable extra type checking
     */
    private bool $strictTypeChecking = true;

    public function __construct(
        HttpClientInterface $httpClient,
        string $baseUrl,
        array $defaultOptions = []
    ) {
        $this->httpClient = $httpClient;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultOptions = $defaultOptions;
    }

    /**
     * Enable or disable strict type checking
     */
    public function setStrictTypeChecking(bool $enabled): self
    {
        $this->strictTypeChecking = $enabled;
        return $this;
    }

    /**
     * Get information about the SyncopateDB API.
     */
    public function getInfo(): array
    {
        return $this->request('GET', '/');
    }

    /**
     * Get server settings.
     */
    public function getSettings(): array
    {
        return $this->request('GET', '/settings');
    }

    /**
     * Get a list of entity types.
     */
    public function getEntityTypes(): array
    {
        return $this->request('GET', '/api/v1/entity-types');
    }

    /**
     * Get a specific entity type definition.
     */
    public function getEntityType(string $name): array
    {
        return $this->request('GET', "/api/v1/entity-types/$name");
    }

    /**
     * Create a new entity type.
     */
    public function createEntityType(array $entityType): array
    {
        $sanitized = $this->deepSanitizeArray($entityType);
        return $this->request('POST', '/api/v1/entity-types', ['json' => $sanitized]);
    }

    /**
     * Update an entity type.
     */
    public function updateEntityType(string $name, array $entityType): array
    {
        $sanitized = $this->deepSanitizeArray($entityType);
        return $this->request('PUT', "/api/v1/entity-types/$name", ['json' => $sanitized]);
    }

    /**
     * Get entities of a specific type.
     */
    public function getEntities(string $type, array $queryParams = []): array
    {
        $sanitized = $this->deepSanitizeArray($queryParams);
        return $this->request('GET', "/api/v1/entities/$type", ['query' => $sanitized]);
    }

    /**
     * Create a new entity.
     */
    public function createEntity(string $type, array $entity): array
    {
        $sanitized = $this->deepSanitizeArray($entity);
        return $this->request('POST', "/api/v1/entities/$type", ['json' => $sanitized]);
    }

    /**
     * Get a specific entity.
     */
    public function getEntity(string $type, string $id): array
    {
        return $this->request('GET', "/api/v1/entities/$type/$id");
    }

    /**
     * Update an entity.
     */
    public function updateEntity(string $type, string $id, array $fields): array
    {
        $sanitized = $this->deepSanitizeArray(['fields' => $fields]);
        return $this->request('PUT', "/api/v1/entities/$type/$id", ['json' => $sanitized]);
    }

    /**
     * Delete an entity.
     */
    public function deleteEntity(string $type, string $id): array
    {
        return $this->request('DELETE', "/api/v1/entities/$type/$id");
    }

    /**
     * Execute a query with type checking.
     */
    public function query(array $queryOptions): array
    {
        // Deep sanitize to fix array to string conversion issues
        $sanitized = $this->deepSanitizeArray($queryOptions);

        // Additional check for data type issues when strict checking is enabled
        if ($this->strictTypeChecking) {
            $this->validateQueryOptions($sanitized);
        }

        return $this->request('POST', '/api/v1/query', ['json' => $sanitized]);
    }

    /**
     * Execute a join query.
     */
    public function joinQuery(array $joinQueryOptions): array
    {
        // Deep sanitize to fix array to string conversion issues
        $sanitized = $this->deepSanitizeArray($joinQueryOptions);

        // Additional check for data type issues when strict checking is enabled
        if ($this->strictTypeChecking) {
            $this->validateQueryOptions($sanitized);
        }

        return $this->request('POST', '/api/v1/query/join', ['json' => $sanitized]);
    }

    /**
     * Check the server health.
     */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * Deep sanitize an array to fix all possible array-to-string conversion issues
     * This method recursively processes all values to ensure they can be safely JSON-encoded
     */
    private function deepSanitizeArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively sanitize nested arrays
                $result[$key] = $this->deepSanitizeArray($value);
            } elseif (is_object($value)) {
                // Handle objects that might cause conversion issues
                if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
                    // Format DateTime objects as ISO strings
                    $result[$key] = $value->format(\DateTimeInterface::ATOM);
                } elseif (method_exists($value, 'toArray')) {
                    // Convert objects that have toArray method
                    $array = $value->toArray();
                    $result[$key] = is_array($array) ? $this->deepSanitizeArray($array) : $array;
                } elseif (method_exists($value, '__toString')) {
                    // Convert objects that have __toString method
                    $result[$key] = (string)$value;
                } else {
                    // Last resort: try to convert to array via JSON
                    try {
                        $array = json_decode(json_encode($value), true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($array)) {
                            $result[$key] = $this->deepSanitizeArray($array);
                        } else {
                            // If JSON conversion fails, store class name
                            $result[$key] = '[Object of class: ' . get_class($value) . ']';
                        }
                    } catch (\Throwable $e) {
                        // If all else fails, store class name
                        $result[$key] = '[Object of class: ' . get_class($value) . ']';
                    }
                }
            } elseif (is_resource($value)) {
                // Resources cannot be JSON-encoded
                $result[$key] = '[Resource: ' . get_resource_type($value) . ']';
            } else {
                // Scalar values can be stored as-is
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Validate query options for potential issues
     * Throws exception if issues are found
     */
    private function validateQueryOptions(array $options): void
    {
        // Check for issues in the filters
        if (isset($options['filters']) && is_array($options['filters'])) {
            foreach ($options['filters'] as $index => $filter) {
                // Each filter should have field, operator, and value
                if (!isset($filter['field']) || !is_string($filter['field'])) {
                    throw new \InvalidArgumentException("Filter at index $index is missing a valid 'field' property");
                }

                if (!isset($filter['operator']) || !is_string($filter['operator'])) {
                    throw new \InvalidArgumentException("Filter at index $index is missing a valid 'operator' property");
                }

                // Value can be various types depending on the operator
                if (!isset($filter['value']) && $filter['value'] !== null) {
                    throw new \InvalidArgumentException("Filter at index $index is missing 'value' property");
                }

                // If operator is 'in', 'array_contains_any', or 'array_contains_all', value should be array
                if (in_array($filter['operator'], ['in', 'array_contains_any', 'array_contains_all']) && !is_array($filter['value'])) {
                    throw new \InvalidArgumentException("Filter at index $index with operator '{$filter['operator']}' requires an array value");
                }
            }
        }

        // Check for issues in joins (for JoinQueryOptions)
        if (isset($options['joins']) && is_array($options['joins'])) {
            foreach ($options['joins'] as $index => $join) {
                if (!isset($join['entityType']) || !is_string($join['entityType'])) {
                    throw new \InvalidArgumentException("Join at index $index is missing a valid 'entityType' property");
                }

                if (!isset($join['localField']) || !is_string($join['localField'])) {
                    throw new \InvalidArgumentException("Join at index $index is missing a valid 'localField' property");
                }

                if (!isset($join['foreignField']) || !is_string($join['foreignField'])) {
                    throw new \InvalidArgumentException("Join at index $index is missing a valid 'foreignField' property");
                }

                if (!isset($join['as']) || !is_string($join['as'])) {
                    throw new \InvalidArgumentException("Join at index $index is missing a valid 'as' property");
                }

                // Validate nested filters in joins
                if (isset($join['filters']) && is_array($join['filters'])) {
                    foreach ($join['filters'] as $filterIndex => $filter) {
                        if (!isset($filter['field']) || !is_string($filter['field'])) {
                            throw new \InvalidArgumentException("Join filter at index $index.$filterIndex is missing a valid 'field' property");
                        }

                        if (!isset($filter['operator']) || !is_string($filter['operator'])) {
                            throw new \InvalidArgumentException("Join filter at index $index.$filterIndex is missing a valid 'operator' property");
                        }
                    }
                }
            }
        }
    }

    /**
     * Send a request to the SyncopateDB API with memory optimization.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $requestOptions = array_merge($this->defaultOptions, $options);
        $requestOptions['buffer'] = false;

        // Validate/sanitize JSON payload
        if (isset($requestOptions['json']) && is_array($requestOptions['json'])) {
            try {
                json_encode($requestOptions['json'], JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $requestOptions['json'] = DebugHelper::sanitizeForJson($requestOptions['json']);
            }
        }

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            return $this->processStreamedResponse($response);
        } catch (ClientException | ServerException $e) {
            // These are the specific HTTP exceptions that are actually thrown
            $response = $e->getResponse();
            $statusCode = $response instanceof ResponseInterface ? $response->getStatusCode() : 0;
            $error = $response instanceof ResponseInterface ? $this->parseErrorResponse($response) : [];

            throw new SyncopateApiException(
                $error['message'] ?? $e->getMessage(),
                $statusCode,
                $e,
                $error
            );
        } catch (TransportExceptionInterface $e) {
            throw new SyncopateApiException(
                'Network error communicating with SyncopateDB API: ' . $e->getMessage(),
                0,
                $e
            );
        } catch (\JsonException $e) {
            throw new SyncopateApiException(
                'Failed to encode request data to JSON: ' . $e->getMessage(),
                0,
                $e
            );
        } catch (\Throwable $e) {
            throw new SyncopateApiException(
                'Unexpected error communicating with SyncopateDB API: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Process response in a memory-efficient way by streaming chunks
     */
    private function processStreamedResponse(ResponseInterface $response): array
    {
        // Stream processing is better for large responses
        $contentLength = $response->getHeaders(false)['content-length'][0] ?? 0;
        if ((int)$contentLength > self::RESPONSE_CHUNK_SIZE) {
            return $this->streamResponse($response);
        }

        // For smaller responses, standard processing is fine
        try {
            return $response->toArray(false);
        } catch (\Throwable $e) {
            throw new SyncopateApiException(
                'Failed to parse response: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Stream large response to prevent memory issues
     */
    private function streamResponse(ResponseInterface $response): array
    {
        $content = '';
        $chunkCount = 0;

        // Stream the response content in chunks
        foreach ($response->toArray(false) as $chunk) {
            $content .= $chunk;
            $chunkCount++;

            // Free memory after processing chunks
            if ($chunkCount % 10 === 0) {
                gc_collect_cycles();
            }
        }

        // Parse the complete content
        try {
            $result = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            // Free memory
            unset($content);
            gc_collect_cycles();

            return $result;
        } catch (\JsonException $e) {
            // Free memory even on exception
            unset($content);
            gc_collect_cycles();

            throw new SyncopateApiException(
                'Failed to parse JSON response: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Parse an error response with memory optimization
     */
    private function parseErrorResponse(ResponseInterface $response): array
    {
        try {
            // For error responses, they're typically small so we can use toArray
            return $response->toArray(false);
        } catch (\Throwable $e) {
            return [
                'message' => 'Could not parse error response: ' . $e->getMessage(),
                'code' => $response->getStatusCode()
            ];
        }
    }

    /**
     * Execute a count query
     */
    public function queryCount(array $queryOptions): array
    {
        // Deep sanitize to fix an array to string conversion issues
        $sanitized = $this->deepSanitizeArray($queryOptions);

        // Additional check for data type issues when strict checking is enabled
        if ($this->strictTypeChecking) {
            $this->validateQueryOptions($sanitized);
        }

        return $this->request('POST', '/api/v1/query/count', ['json' => $sanitized]);
    }

    /**
     * Generate a cURL command from request parameters
     * This is for investigation when breakpoint debugging
     */
    private function generateCurlCommand(string $method, string $url, array $options): string
    {
        $curlCommand = 'curl -X ' . $method . ' "' . $url . '"';

        // Add headers
        if (isset($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $curlCommand .= ' -H "' . $name . ': ' . $value . '"';
            }
        }

        // Add JSON body if present
        if (isset($options['json'])) {
            $jsonData = json_encode($options['json'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $curlCommand .= ' -H "Content-Type: application/json"';
            $curlCommand .= ' -d \'' . $jsonData . '\'';
        }

        // Add form data if present
        if (isset($options['body'])) {
            $curlCommand .= ' -d \'' . $options['body'] . '\'';
        }

        // Add query parameters if present
        if (isset($options['query']) && is_array($options['query'])) {
            // Check if URL already has query parameters
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $queryString = http_build_query($options['query']);
            if (!empty($queryString)) {
                $curlCommand .= ' "' . $separator . $queryString . '"';
            }
        }

        // Add other common curl options
        $curlCommand .= ' --location'; // follow redirects

        // Add timeout if specified
        if (isset($options['timeout'])) {
            $curlCommand .= ' --max-time ' . $options['timeout'];
        }

        return $curlCommand;
    }
}