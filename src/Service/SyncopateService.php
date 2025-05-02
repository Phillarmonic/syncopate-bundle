<?php

namespace Phillarmonic\SyncopateBundle\Service;

use Phillarmonic\SyncopateBundle\Attribute\Relationship;
use Phillarmonic\SyncopateBundle\Client\SyncopateClient;
use Phillarmonic\SyncopateBundle\Exception\SyncopateApiException;
use Phillarmonic\SyncopateBundle\Exception\SyncopateValidationException;
use Phillarmonic\SyncopateBundle\Mapper\EntityMapper;
use Phillarmonic\SyncopateBundle\Model\EntityDefinition;
use Phillarmonic\SyncopateBundle\Model\JoinQueryOptions;
use Phillarmonic\SyncopateBundle\Model\QueryFilter;
use Phillarmonic\SyncopateBundle\Model\QueryOptions;

class SyncopateService
{
    private SyncopateClient $client;
    private EntityTypeRegistry $entityTypeRegistry;
    private EntityMapper $entityMapper;
    private RelationshipRegistry $relationshipRegistry;

    public function __construct(
        SyncopateClient $client,
        EntityTypeRegistry $entityTypeRegistry,
        EntityMapper $entityMapper,
        RelationshipRegistry $relationshipRegistry
    ) {
        $this->client = $client;
        $this->entityTypeRegistry = $entityTypeRegistry;
        $this->entityMapper = $entityMapper;
        $this->relationshipRegistry = $relationshipRegistry;
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
    public function delete(object $entity, bool $enableCascade = true): bool
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

        // Process cascade delete if enabled
        if ($enableCascade) {
            $this->processCascadeDelete($entity);
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
     * Process cascade delete for related entities
     */
    private function processCascadeDelete(object $entity): void
    {
        $className = get_class($entity);
        $reflection = new \ReflectionClass($className);

        // Iterate through properties to find relationships with cascade delete
        foreach ($reflection->getProperties() as $property) {
            $relationshipAttributes = $property->getAttributes(Relationship::class);

            if (empty($relationshipAttributes)) {
                continue;
            }

            /** @var Relationship $relationshipAttribute */
            $relationshipAttribute = $relationshipAttributes[0]->newInstance();

            // Only process properties with cascade="remove"
            if ($relationshipAttribute->cascade !== Relationship::CASCADE_REMOVE) {
                continue;
            }

            // Access the property value
            $property->setAccessible(true);

            // Skip if the property hasn't been initialized
            if (!$property->isInitialized($entity)) {
                continue;
            }

            $relatedData = $property->getValue($entity);

            if ($relatedData === null) {
                continue;
            }

            $targetEntityClass = $relationshipAttribute->targetEntity;

            // Handle based on relationship type
            switch ($relationshipAttribute->type) {
                case Relationship::TYPE_ONE_TO_ONE:
                case Relationship::TYPE_MANY_TO_ONE:
                    // Single entity
                    $this->delete($relatedData, true);
                    break;

                case Relationship::TYPE_ONE_TO_MANY:
                case Relationship::TYPE_MANY_TO_MANY:
                    // Collection of entities
                    foreach ($relatedData as $relatedEntity) {
                        $this->delete($relatedEntity, true);
                    }
                    break;
            }
        }
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
    /**
     * Execute a join query
     */
    public function joinQuery(string $className, JoinQueryOptions $joinQueryOptions): array
    {
        // Get entity type from entity class
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // Make sure the query is for the correct entity type
        if ($joinQueryOptions->getEntityType() !== $entityType) {
            throw new \InvalidArgumentException("Query entity type does not match class entity type");
        }

        // Execute join query
        $response = $this->client->joinQuery($joinQueryOptions->toArray());

        // Map results to entity objects
        $entities = [];
        foreach ($response['data'] as $entityData) {
            $entity = $this->entityMapper->mapToObject($entityData, $className);

            // Process joined data
            foreach ($entityData as $key => $value) {
                // Skip standard entity fields
                if (in_array($key, ['id', 'fields'])) {
                    continue;
                }

                // This is a joined entity or collection
                $reflection = new \ReflectionProperty($entity, $key);
                if (!$reflection->isInitialized($entity)) {
                    $reflection->setAccessible(true);
                    $reflection->setValue($entity, $value);
                }
            }

            $entities[] = $entity;
        }

        return $entities;
    }
}