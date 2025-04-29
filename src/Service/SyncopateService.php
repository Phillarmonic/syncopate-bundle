<?php

namespace Philharmonic\SyncopateBundle\Service;

use Philharmonic\SyncopateBundle\Client\SyncopateClient;
use Philharmonic\SyncopateBundle\Exception\SyncopateApiException;
use Philharmonic\SyncopateBundle\Exception\SyncopateValidationException;
use Philharmonic\SyncopateBundle\Mapper\EntityMapper;
use Philharmonic\SyncopateBundle\Model\EntityDefinition;
use Philharmonic\SyncopateBundle\Model\QueryFilter;
use Philharmonic\SyncopateBundle\Model\QueryOptions;

class SyncopateService
{
    private SyncopateClient $client;
    private EntityTypeRegistry $entityTypeRegistry;
    private EntityMapper $entityMapper;

    public function __construct(
        SyncopateClient $client,
        EntityTypeRegistry $entityTypeRegistry,
        EntityMapper $entityMapper
    ) {
        $this->client = $client;
        $this->entityTypeRegistry = $entityTypeRegistry;
        $this->entityMapper = $entityMapper;
    }

    /**
     * Get information about the SyncopateDB server
     */
    public function getServerInfo(): array
    {
        return $this->client->getInfo();
    }

    /**
     * Get SyncopateDB server settings
     */
    public function getServerSettings(): array
    {
        return $this->client->getSettings();
    }

    /**
     * Check SyncopateDB server health
     */
    public function checkHealth(): array
    {
        return $this->client->health();
    }

    /**
     * Get all entity types
     */
    public function getEntityTypes(): array
    {
        return $this->client->getEntityTypes();
    }

    /**
     * Get entity type definition
     */
    public function getEntityType(string $name): EntityDefinition
    {
        $data = $this->client->getEntityType($name);
        return EntityDefinition::fromArray($data);
    }

    /**
     * Create a new entity
     */
    public function create(object $entity): object
    {
        // Validate the entity
        $this->entityMapper->validateObject($entity);

        // Get entity type from entity class
        $className = get_class($entity);
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Map entity to data for API
        $data = $this->entityMapper->mapFromObject($entity);

        // Create entity in SyncopateDB
        $response = $this->client->createEntity($entityType, $data);

        if (!isset($response['id'])) {
            throw new SyncopateApiException("Failed to create entity: ID not returned");
        }

        // Get the created entity
        return $this->getById($className, $response['id']);
    }

    /**
     * Get entity by ID
     */
    public function getById(string $className, string|int $id): object
    {
        // Get entity type from entity class
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Format ID based on entity type's ID generator
        $idString = (string) $id;

        // Get entity from SyncopateDB
        $response = $this->client->getEntity($entityType, $idString);

        // Map API response to entity object
        return $this->entityMapper->mapToObject($response, $className);
    }

    /**
     * Update an entity
     */
    public function update(object $entity): object
    {
        // Validate the entity
        $this->entityMapper->validateObject($entity);

        // Get entity type from entity class
        $className = get_class($entity);
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Map entity to data for API
        $data = $this->entityMapper->mapFromObject($entity);

        if (!isset($data['id'])) {
            throw new SyncopateValidationException("Entity must have an ID for update operation");
        }

        // Update entity in SyncopateDB
        $response = $this->client->updateEntity($entityType, (string) $data['id'], $data['fields']);

        // Get the updated entity
        return $this->getById($className, $data['id']);
    }

    /**
     * Delete an entity
     */
    public function delete(object $entity): bool
    {
        // Get entity type from entity class
        $className = get_class($entity);
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Map entity to data for API
        $data = $this->entityMapper->mapFromObject($entity);

        if (!isset($data['id'])) {
            throw new SyncopateValidationException("Entity must have an ID for delete operation");
        }

        // Delete entity from SyncopateDB
        $response = $this->client->deleteEntity($entityType, (string) $data['id']);

        return isset($response['message']) && strpos($response['message'], 'successfully') !== false;
    }

    /**
     * Delete an entity by ID
     */
    public function deleteById(string $className, string|int $id): bool
    {
        // Get entity type from entity class
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Delete entity from SyncopateDB
        $response = $this->client->deleteEntity($entityType, (string) $id);

        return isset($response['message']) && strpos($response['message'], 'successfully') !== false;
    }

    /**
     * Find entities by criteria
     */
    public function findBy(string $className, array $criteria = [], array $orderBy = [], int $limit = null, int $offset = 0): array
    {
        // Get entity type from entity class
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Build query options
        $queryOptions = new QueryOptions($entityType);
        $queryOptions->setOffset($offset);

        if ($limit !== null) {
            $queryOptions->setLimit($limit);
        }

        // Add filters for criteria
        foreach ($criteria as $field => $value) {
            $queryOptions->addFilter(QueryFilter::eq($field, $value));
        }

        // Add order by
        if (!empty($orderBy)) {
            // We only support single field ordering for now
            $field = key($orderBy);
            $direction = current($orderBy);

            $queryOptions->setOrderBy($field);
            $queryOptions->setOrderDesc($direction === 'DESC');
        }

        // Execute query
        $response = $this->client->query($queryOptions->toArray());

        // Map results to entity objects
        $entities = [];
        foreach ($response['data'] as $entityData) {
            $entities[] = $this->entityMapper->mapToObject($entityData, $className);
        }

        return $entities;
    }

    /**
     * Find one entity by criteria
     */
    public function findOneBy(string $className, array $criteria = []): ?object
    {
        $results = $this->findBy($className, $criteria, [], 1);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Find all entities of a type
     */
    public function findAll(string $className): array
    {
        return $this->findBy($className);
    }

    /**
     * Execute a custom query
     */
    public function query(string $className, QueryOptions $queryOptions): array
    {
        // Get entity type from entity class
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Make sure the query is for the correct entity type
        if ($queryOptions->getEntityType() !== $entityType) {
            throw new \InvalidArgumentException("Query entity type does not match class entity type");
        }

        // Execute query
        $response = $this->client->query($queryOptions->toArray());

        // Map results to entity objects
        $entities = [];
        foreach ($response['data'] as $entityData) {
            $entities[] = $this->entityMapper->mapToObject($entityData, $className);
        }

        return $entities;
    }

    /**
     * Count entities matching criteria
     */
    public function count(string $className, array $criteria = []): int
    {
        // Get entity type from entity class
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Build query options with limit 0 to just get count
        $queryOptions = new QueryOptions($entityType);
        $queryOptions->setLimit(0);

        // Add filters for criteria
        foreach ($criteria as $field => $value) {
            $queryOptions->addFilter(QueryFilter::eq($field, $value));
        }

        // Execute query
        $response = $this->client->query($queryOptions->toArray());

        return $response['total'] ?? 0;
    }
}