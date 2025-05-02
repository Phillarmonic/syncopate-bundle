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
     * @throws \ReflectionException
     */
    private function processCascadeDelete(object $entity): void
    {
        $className = get_class($entity);
        $entityType = $this->entityTypeRegistry->getEntityType($className);
        $reflection = new \ReflectionClass($className);

        // Get entity ID
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $entityId = $idProperty->getValue($entity);

        if (!$entityId) {
            return; // Can't process cascade if entity has no ID
        }

        // Get relationship metadata
        $metadata = $this->relationshipRegistry->getRelationshipMetadata($className);
        $relationships = $metadata->getRelationships();

        foreach ($relationships as $propertyName => $relationshipData) {
            // Skip if not a cascade delete relationship
            if ($relationshipData['cascade'] !== Relationship::CASCADE_REMOVE) {
                continue;
            }

            $property = $relationshipData['property'];
            $property->setAccessible(true);
            $targetEntityClass = $relationshipData['targetEntity'];
            $relationType = $relationshipData['type'];

            // First check if the property is already initialized and has data
            $relatedEntities = [];
            $hasData = false;

            if ($property->isInitialized($entity)) {
                $relatedData = $property->getValue($entity);
                $hasData = !empty($relatedData);

                if ($hasData) {
                    if (is_array($relatedData) || $relatedData instanceof \Traversable) {
                        $relatedEntities = $relatedData;
                    } else {
                        $relatedEntities = [$relatedData];
                    }
                }
            }

            // If no data is loaded, try to load the related entities based on relationship type
            if (!$hasData) {
                $targetEntityType = $this->entityTypeRegistry->getEntityType($targetEntityClass);

                if ($targetEntityType === null) {
                    continue; // Skip if target entity type is not registered
                }

                switch ($relationType) {
                    case Relationship::TYPE_ONE_TO_MANY:
                        // For one-to-many, find all target entities referencing this entity
                        // Use mappedBy to determine the field in the target entity
                        if (!empty($relationshipData['mappedBy'])) {
                            // Find related entities using filter on mappedBy property
                            $queryOptions = new QueryOptions($targetEntityType);
                            $queryOptions->addFilter(QueryFilter::eq($relationshipData['mappedBy'] . 'Id', $entityId));
                            $response = $this->client->query($queryOptions->toArray());

                            if (isset($response['data']) && !empty($response['data'])) {
                                foreach ($response['data'] as $relatedEntityData) {
                                    $relatedEntities[] = $this->entityMapper->mapToObject($relatedEntityData, $targetEntityClass);
                                }
                            }
                        }
                        break;

                    case Relationship::TYPE_MANY_TO_ONE:
                        // For many-to-one, we don't typically cascade delete upward
                        // But if specified, find the parent entity
                        if (!empty($relationshipData['inversedBy'])) {
                            $joinColumn = $relationshipData['joinColumn'] ?? $propertyName . 'Id';
                            $joinColumnValue = null;

                            // Try to get the join column value from the entity
                            $joinColumnProperty = $reflection->hasProperty($joinColumn)
                                ? $reflection->getProperty($joinColumn)
                                : null;

                            if ($joinColumnProperty) {
                                $joinColumnProperty->setAccessible(true);
                                $joinColumnValue = $joinColumnProperty->getValue($entity);
                            }

                            if ($joinColumnValue) {
                                try {
                                    $relatedEntity = $this->getById($targetEntityClass, $joinColumnValue);
                                    $relatedEntities[] = $relatedEntity;
                                } catch (\Throwable $e) {
                                    // Entity not found, ignore
                                }
                            }
                        }
                        break;

                    case Relationship::TYPE_ONE_TO_ONE:
                        // For one-to-one, there are two cases:
                        // 1. This entity is the owner (has the FK) - no need to load anything
                        // 2. This entity is not the owner (mapped by other side) - find related entity
                        if (!empty($relationshipData['mappedBy'])) {
                            // This entity is not the owner, find the related entity
                            $queryOptions = new QueryOptions($targetEntityType);
                            $queryOptions->addFilter(QueryFilter::eq($relationshipData['mappedBy'] . 'Id', $entityId));
                            $queryOptions->setLimit(1);
                            $response = $this->client->query($queryOptions->toArray());

                            if (isset($response['data'][0])) {
                                $relatedEntities[] = $this->entityMapper->mapToObject($response['data'][0], $targetEntityClass);
                            }
                        } else if (!empty($relationshipData['inversedBy'])) {
                            // This entity is the owner, get the related entity by FK
                            $joinColumn = $relationshipData['joinColumn'] ?? $propertyName . 'Id';
                            $joinColumnProperty = $reflection->hasProperty($joinColumn)
                                ? $reflection->getProperty($joinColumn)
                                : null;

                            if ($joinColumnProperty) {
                                $joinColumnProperty->setAccessible(true);
                                $joinColumnValue = $joinColumnProperty->getValue($entity);

                                if ($joinColumnValue) {
                                    try {
                                        $relatedEntity = $this->getById($targetEntityClass, $joinColumnValue);
                                        $relatedEntities[] = $relatedEntity;
                                    } catch (\Throwable $e) {
                                        // Entity isn't found, ignore
                                    }
                                }
                            }
                        }
                        break;

                    case Relationship::TYPE_MANY_TO_MANY:
                        // For many-to-many, need to find entities from join table
                        // This is more complex and might need separate join table handling
                        if (!empty($relationshipData['joinTable'])) {
                            // TODO: Implement many-to-many cascading via join table
                            // Would require custom query or additional processing
                        }
                        break;
                }
            }

            // Now delete all related entities
            foreach ($relatedEntities as $relatedEntity) {
                if (is_object($relatedEntity) && is_a($relatedEntity, $targetEntityClass)) {
                    try {
                        $this->delete($relatedEntity, true); // Recursive cascade
                    } catch (\Throwable $e) {
                        // Log error but continue with other entities
                        // Could add logging here
                    }
                }
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