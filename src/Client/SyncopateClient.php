<?php

namespace Phillarmonic\SyncopateBundle\Client;

use Phillarmonic\SyncopateBundle\Exception\SyncopateApiException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SyncopateClient
{
    private HttpClientInterface $httpClient;
    private string $baseUrl;
    private array $defaultOptions;

    private const RESPONSE_CHUNK_SIZE = 1024 * 512; // 512KB chunks

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
        return $this->request('POST', '/api/v1/entity-types', ['json' => $entityType]);
    }

    /**
     * Update an entity type.
     */
    public function updateEntityType(string $name, array $entityType): array
    {
        return $this->request('PUT', "/api/v1/entity-types/$name", ['json' => $entityType]);
    }

    /**
     * Get entities of a specific type.
     */
    public function getEntities(string $type, array $queryParams = []): array
    {
        return $this->request('GET', "/api/v1/entities/$type", ['query' => $queryParams]);
    }

    /**
     * Create a new entity.
     */
    public function createEntity(string $type, array $entity): array
    {
        return $this->request('POST', "/api/v1/entities/$type", ['json' => $entity]);
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
        return $this->request('PUT', "/api/v1/entities/$type/$id", ['json' => ['fields' => $fields]]);
    }

    /**
     * Delete an entity.
     */
    public function deleteEntity(string $type, string $id): array
    {
        return $this->request('DELETE', "/api/v1/entities/$type/$id");
    }

    /**
     * Execute a query.
     */
    public function query(array $queryOptions): array
    {
        return $this->request('POST', '/api/v1/query', ['json' => $queryOptions]);
    }

    /**
     * Execute a join query.
     */
    public function joinQuery(array $joinQueryOptions): array
    {
        return $this->request('POST', '/api/v1/query/join', ['json' => $joinQueryOptions]);
    }

    /**
     * Check the server health.
     */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * Send a request to the SyncopateDB API with memory optimization.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $requestOptions = array_merge($this->defaultOptions, $options);

        // Add memory-related options
        $requestOptions['buffer'] = false; // Don't buffer entire response

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            return $this->processStreamedResponse($response);
        } catch (ClientException|ServerException $e) {
            // Try to extract an error message from response
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $error = $this->parseErrorResponse($response);

            throw new SyncopateApiException(
                $error['message'] ?? $e->getMessage(),
                $statusCode,
                $e,
                $error
            );
        } catch (\Throwable $e) {
            throw new SyncopateApiException(
                'Failed to communicate with SyncopateDB API: ' . $e->getMessage(),
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
        if ($response->getHeaders()['content-length'][0] ?? 0 > self::RESPONSE_CHUNK_SIZE) {
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
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SyncopateApiException(
                'Failed to parse JSON response: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            // Free memory
            unset($content);
            gc_collect_cycles();
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
}