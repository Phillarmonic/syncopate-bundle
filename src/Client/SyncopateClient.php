<?php

namespace Philharmonic\SyncopateBundle\Client;

use Philharmonic\SyncopateBundle\Exception\SyncopateApiException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SyncopateClient
{
    private HttpClientInterface $httpClient;
    private string $baseUrl;
    private array $defaultOptions;

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
     * Get information about the SyncopateDB server.
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
     * Check the server health.
     */
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * Send a request to the SyncopateDB API.
     */
    private function request(string $method, string $endpoint, array $options = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $requestOptions = array_merge($this->defaultOptions, $options);

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            return $this->processResponse($response);
        } catch (ClientException|ServerException $e) {
            // Try to extract error message from response
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $error = $this->parseErrorResponse($response);

            throw new SyncopateApiException(
                $error['message'] ?? $e->getMessage(),
                $statusCode,
                $e
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
     * Process a successful response.
     */
    private function processResponse(ResponseInterface $response): array
    {
        return $response->toArray();
    }

    /**
     * Parse an error response.
     */
    private function parseErrorResponse(ResponseInterface $response): array
    {
        try {
            return $response->toArray(false);
        } catch (\Throwable $e) {
            return [
                'message' => 'Could not parse error response: ' . $e->getMessage(),
                'code' => $response->getStatusCode()
            ];
        }
    }
}