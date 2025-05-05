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
    private const BATCH_SIZE = 25;

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

        // Return the updated entity without fetching it again to save memory
        $updatedData = [
            'id' => $data['id'],
            'fields' => $data['fields'],
        ];

        return $this->entityMapper->mapToObject($updatedData, $className);
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
     * Process cascade delete for related entities with memory optimization
     * @throws \ReflectionException
     */
    private function processCascadeDelete(object $entity): void
    {
        $className = get_class($entity);
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
            $targetEntityType = $this->entityTypeRegistry->getEntityType($targetEntityClass);

            if ($targetEntityType === null) {
                continue; // Skip if target entity type is not registered
            }

            // Process relationship in batches to save memory
            switch ($relationType) {
                case Relationship::TYPE_ONE_TO_MANY:
                    if (!empty($relationshipData['mappedBy'])) {
                        $this->cascadeDeleteOneToMany(
                            $entityId,
                            $targetEntityClass,
                            $targetEntityType,
                            $relationshipData['mappedBy']
                        );
                    }
                    break;

                case Relationship::TYPE_MANY_TO_ONE:
                    // For many-to-one, we don't typically cascade delete upward
                    // but if specified, handle the parent entity
                    if (!empty($relationshipData['inversedBy'])) {
                        $joinColumn = $relationshipData['joinColumn'] ?? $propertyName . 'Id';
                        $joinColumnValue = null;

                        $joinColumnProperty = $reflection->hasProperty($joinColumn)
                            ? $reflection->getProperty($joinColumn)
                            : null;

                        if ($joinColumnProperty) {
                            $joinColumnProperty->setAccessible(true);
                            $joinColumnValue = $joinColumnProperty->getValue($entity);
                        }

                        if ($joinColumnValue) {
                            try {
                                $this->deleteById($targetEntityClass, $joinColumnValue);
                            } catch (\Throwable $e) {
                                // Entity not found, ignore
                            }
                        }
                    }
                    break;

                case Relationship::TYPE_ONE_TO_ONE:
                    if (!empty($relationshipData['mappedBy'])) {
                        // This entity is not the owner, find and delete the related entity
                        $this->cascadeDeleteOneToOne(
                            $entityId,
                            $targetEntityClass,
                            $targetEntityType,
                            $relationshipData['mappedBy']
                        );
                    } else if (!empty($relationshipData['inversedBy'])) {
                        // This entity is the owner, delete the related entity by FK
                        $joinColumn = $relationshipData['joinColumn'] ?? $propertyName . 'Id';
                        $joinColumnProperty = $reflection->hasProperty($joinColumn)
                            ? $reflection->getProperty($joinColumn)
                            : null;

                        if ($joinColumnProperty) {
                            $joinColumnProperty->setAccessible(true);
                            $joinColumnValue = $joinColumnProperty->getValue($entity);

                            if ($joinColumnValue) {
                                try {
                                    $this->deleteById($targetEntityClass, $joinColumnValue);
                                } catch (\Throwable $e) {
                                    // Entity not found, ignore
                                }
                            }
                        }
                    }
                    break;

                case Relationship::TYPE_MANY_TO_MANY:
                    // For many-to-many relationships with join tables
                    // This would require custom implementation based on your join table structure
                    break;
            }
        }
    }

    /**
     * Process cascade delete for one-to-many relationships in batches
     */
    private function cascadeDeleteOneToMany(
        string|int $entityId,
        string $targetEntityClass,
        string $targetEntityType,
        string $mappedByField
    ): void {
        $offset = 0;
        $hasMore = true;

        while ($hasMore) {
            // Query for a batch of related entities
            $queryOptions = new QueryOptions($targetEntityType);
            $queryOptions->addFilter(QueryFilter::eq($mappedByField . 'Id', $entityId));
            $queryOptions->setLimit(self::BATCH_SIZE);
            $queryOptions->setOffset($offset);

            try {
                $response = $this->client->query($queryOptions->toArray());

                if (empty($response['data'])) {
                    $hasMore = false;
                } else {
                    // Process this batch
                    foreach ($response['data'] as $relatedEntityData) {
                        $relatedId = $relatedEntityData['id'] ?? null;
                        if ($relatedId) {
                            try {
                                $this->deleteById($targetEntityClass, $relatedId);
                            } catch (\Throwable $e) {
                                // Log error but continue with other entities
                            }
                        }
                    }

                    // If we got fewer results than the batch size, we're done
                    if (count($response['data']) < self::BATCH_SIZE) {
                        $hasMore = false;
                    } else {
                        $offset += self::BATCH_SIZE;
                    }
                }
            } catch (\Throwable $e) {
                // Error querying, stop processing
                $hasMore = false;
            }

            // Free memory
            gc_collect_cycles();
        }
    }

    /**
     * Process cascade delete for one-to-one relationships
     */
    private function cascadeDeleteOneToOne(
        string|int $entityId,
        string $targetEntityClass,
        string $targetEntityType,
        string $mappedByField
    ): void {
        $queryOptions = new QueryOptions($targetEntityType);
        $queryOptions->addFilter(QueryFilter::eq($mappedByField . 'Id', $entityId));
        $queryOptions->setLimit(1);

        try {
            $response = $this->client->query($queryOptions->toArray());

            if (!empty($response['data'][0])) {
                $relatedId = $response['data'][0]['id'] ?? null;
                if ($relatedId) {
                    $this->deleteById($targetEntityClass, $relatedId);
                }
            }
        } catch (\Throwable $e) {
            // Entity not found or error, ignore
        }
    }

    /**
     * Find entities by criteria with batching support
     */
    public function findBy(string $className, array $criteria = [], array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        // Get entity type from entity class
        $entityType = $this->entityTypeRegistry->getEntityType($className);

        if ($entityType === null) {
            throw new \InvalidArgumentException("Class $className is not registered as an entity");
        }

        // If no limit specified or large limit, use batching
        if ($limit === null || $limit > self::BATCH_SIZE) {
            return $this->findByWithBatching($className, $entityType, $criteria, $orderBy, $limit, $offset);
        }

        // For small limits, use standard logic
        $queryOptions = new QueryOptions($entityType);
        $queryOptions->setOffset($offset);
        $queryOptions->setLimit($limit);

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
     * Memory-optimized version that uses batching for large result sets
     */
    private function findByWithBatching(
        string $className,
        string $entityType,
        array $criteria = [],
        array $orderBy = [],
        ?int $limit = null,
        int $offset = 0
    ): array {
        $entities = [];
        $currentOffset = $offset;
        $remainingLimit = $limit;
        $hasMore = true;

        // Get results in batches
        while ($hasMore) {
            $batchSize = ($remainingLimit !== null && $remainingLimit < self::BATCH_SIZE)
                ? $remainingLimit
                : self::BATCH_SIZE;

            $queryOptions = new QueryOptions($entityType);
            $queryOptions->setOffset($currentOffset);
            $queryOptions->setLimit($batchSize);

            // Add filters for criteria
            foreach ($criteria as $field => $value) {
                $queryOptions->addFilter(QueryFilter::eq($field, $value));
            }

            // Add order by
            if (!empty($orderBy)) {
                $field = key($orderBy);
                $direction = current($orderBy);
                $queryOptions->setOrderBy($field);
                $queryOptions->setOrderDesc($direction === 'DESC');
            }

            // Execute query
            $response = $this->client->query($queryOptions->toArray());
            $batchResults = $response['data'] ?? [];

            // Process batch results
            foreach ($batchResults as $entityData) {
                $entities[] = $this->entityMapper->mapToObject($entityData, $className);
            }

            // Update state for next batch
            $resultCount = count($batchResults);
            $currentOffset += $resultCount;

            if ($remainingLimit !== null) {
                $remainingLimit -= $resultCount;
            }

            // Check if we've reached the end
            if (
                $resultCount < $batchSize ||
                ($remainingLimit !== null && $remainingLimit <= 0)
            ) {
                $hasMore = false;
            }

            // Free memory
            gc_collect_cycles();
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
     * Execute a custom query with memory-optimized batching support
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

        // Check if we should use batching
        $limit = $queryOptions->getLimit();
        if ($limit === null || $limit > self::BATCH_SIZE) {
            return $this->executeQueryWithBatching($className, $queryOptions);
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
     * Memory-optimized batched query execution
     */
    private function executeQueryWithBatching(string $className, QueryOptions $queryOptions): array
    {
        $entities = [];
        $originalLimit = $queryOptions->getLimit();
        $originalOffset = $queryOptions->getOffset();
        $currentOffset = $originalOffset;
        $remainingLimit = $originalLimit;
        $hasMore = true;

        // Clone query options to avoid modifying the original
        $clonedOptions = clone $queryOptions;

        while ($hasMore) {
            $batchSize = ($remainingLimit !== null && $remainingLimit < self::BATCH_SIZE)
                ? $remainingLimit
                : self::BATCH_SIZE;

            $clonedOptions->setOffset($currentOffset);
            $clonedOptions->setLimit($batchSize);

            // Execute query for this batch
            $response = $this->client->query($clonedOptions->toArray());
            $batchResults = $response['data'] ?? [];

            // Process batch results
            foreach ($batchResults as $entityData) {
                $entities[] = $this->entityMapper->mapToObject($entityData, $className);
            }

            // Update state for next batch
            $resultCount = count($batchResults);
            $currentOffset += $resultCount;

            if ($remainingLimit !== null) {
                $remainingLimit -= $resultCount;
            }

            // Check if we've reached the end
            if (
                $resultCount < $batchSize ||
                ($remainingLimit !== null && $remainingLimit <= 0)
            ) {
                $hasMore = false;
            }

            // Free memory
            gc_collect_cycles();
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
     * Execute a join query with batching support
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

        // Check if we should use batching
        $limit = $joinQueryOptions->getLimit();
        if ($limit === null || $limit > self::BATCH_SIZE) {
            return $this->executeJoinQueryWithBatching($className, $joinQueryOptions);
        }

        // Execute join query
        $response = $this->client->joinQuery($joinQueryOptions->toArray());

        // Map results to entity objects
        return $this->mapJoinQueryResults($response, $className);
    }

    /**
     * Memory-optimized batched join query execution
     */
    private function executeJoinQueryWithBatching(string $className, JoinQueryOptions $joinQueryOptions): array
    {
        $entities = [];
        $originalLimit = $joinQueryOptions->getLimit();
        $originalOffset = $joinQueryOptions->getOffset();
        $currentOffset = $originalOffset;
        $remainingLimit = $originalLimit;
        $hasMore = true;

        // Clone query options to avoid modifying the original
        $clonedOptions = clone $joinQueryOptions;

        while ($hasMore) {
            $batchSize = ($remainingLimit !== null && $remainingLimit < self::BATCH_SIZE)
                ? $remainingLimit
                : self::BATCH_SIZE;

            $clonedOptions->setOffset($currentOffset);
            $clonedOptions->setLimit($batchSize);

            // Execute join query for this batch
            $response = $this->client->joinQuery($clonedOptions->toArray());
            $batchEntities = $this->mapJoinQueryResults($response, $className);

            // Add batch results to main result set
            foreach ($batchEntities as $entity) {
                $entities[] = $entity;
            }

            // Update state for next batch
            $resultCount = count($batchEntities);
            $currentOffset += $resultCount;

            if ($remainingLimit !== null) {
                $remainingLimit -= $resultCount;
            }

            // Check if we've reached the end
            if (
                $resultCount < $batchSize ||
                ($remainingLimit !== null && $remainingLimit <= 0)
            ) {
                $hasMore = false;
            }

            // Free memory
            unset($batchEntities);
            gc_collect_cycles();
        }

        return $entities;
    }

    /**
     * Map join query results to entity objects
     */
    private function mapJoinQueryResults(array $response, string $className): array
    {
        $entities = [];
        foreach ($response['data'] as $entityData) {
            $entity = $this->entityMapper->mapToObject($entityData, $className);

            // Process joined data at root level
            foreach ($entityData as $key => $value) {
                // Skip standard entity fields
                if (in_array($key, ['id', 'fields', 'type'])) {
                    continue;
                }

                // This is a joined entity or collection
                if (property_exists($entity, $key)) {
                    $reflection = new \ReflectionProperty($entity, $key);
                    $reflection->setAccessible(true);

                    // Check if this is an array property (collection)
                    $type = $reflection->getType();
                    $isArrayProperty = ($type && $type->getName() === 'array');

                    if ($isArrayProperty && is_array($value)) {
                        // For array properties, initialize if not already set
                        if (!$reflection->isInitialized($entity)) {
                            $reflection->setValue($entity, []);
                        }

                        // Get current array value
                        $currentArray = $reflection->getValue($entity);

                        // Get target class for this relationship
                        $targetClass = $this->getRelationshipTargetClass($className, $key);

                        // If we have a target class, create objects for each item
                        if ($targetClass) {
                            foreach ($value as $item) {
                                // Create a new instance of the target entity
                                $targetEntity = new $targetClass();

                                // Get all properties of the target class
                                $targetReflection = new \ReflectionClass($targetClass);
                                $targetProperties = $targetReflection->getProperties();

                                // Map data to properties
                                foreach ($targetProperties as $prop) {
                                    $propName = $prop->getName();
                                    $fieldName = $this->getFieldNameFromProperty($prop);

                                    // Try with direct property name
                                    if (isset($item[$propName])) {
                                        $prop->setAccessible(true);
                                        $prop->setValue($targetEntity, $item[$propName]);
                                    }
                                    // Try with field name from attribute
                                    elseif ($fieldName && isset($item[$fieldName])) {
                                        $prop->setAccessible(true);
                                        $prop->setValue($targetEntity, $item[$fieldName]);
                                    }
                                    // Try with snake_case version
                                    else {
                                        $snakeName = $this->camelToSnake($propName);
                                        if (isset($item[$snakeName])) {
                                            $prop->setAccessible(true);
                                            $prop->setValue($targetEntity, $item[$snakeName]);
                                        }
                                    }
                                }

                                $currentArray[] = $targetEntity;
                            }
                        } else {
                            // If no target class, just add the raw data
                            foreach ($value as $item) {
                                $currentArray[] = $item;
                            }
                        }

                        // Set the updated array back to the property
                        $reflection->setValue($entity, $currentArray);
                    } else {
                        // For non-array properties, set the value directly
                        if (!$reflection->isInitialized($entity)) {
                            $reflection->setValue($entity, $value);
                        }
                    }
                }
            }

            // Also check inside 'fields' for nested joined data
            if (isset($entityData['fields']) && is_array($entityData['fields'])) {
                foreach ($entityData['fields'] as $key => $value) {
                    // If this looks like a joined entity (is an array and not a scalar value)
                    if (is_array($value) && !empty($value) && property_exists($entity, $key)) {
                        $reflection = new \ReflectionProperty($entity, $key);
                        $reflection->setAccessible(true);

                        // Get the target entity class for this property
                        $targetClass = $this->getRelationshipTargetClass($className, $key);

                        if ($targetClass) {
                            // Check if this is an array property (one-to-many relationship)
                            $type = $reflection->getType();
                            $isArrayProperty = ($type && $type->getName() === 'array');

                            if ($isArrayProperty) {
                                // For array properties, initialize if not already set
                                if (!$reflection->isInitialized($entity)) {
                                    $reflection->setValue($entity, []);
                                }

                                // Get current array value
                                $currentArray = $reflection->getValue($entity) ?: [];

                                // Create a new instance for each item and add to array
                                $joinedEntity = new $targetClass();

                                // Map each field to the joined entity, with proper name conversion
                                foreach ($value as $fieldName => $fieldValue) {
                                    // Try direct property matching
                                    if (property_exists($joinedEntity, $fieldName)) {
                                        $fieldReflection = new \ReflectionProperty($joinedEntity, $fieldName);
                                        $fieldReflection->setAccessible(true);
                                        $fieldReflection->setValue($joinedEntity, $fieldValue);
                                    } else {
                                        // Try snake_case to camelCase conversion
                                        $camelFieldName = $this->snakeToCamel($fieldName);
                                        if (property_exists($joinedEntity, $camelFieldName)) {
                                            $fieldReflection = new \ReflectionProperty($joinedEntity, $camelFieldName);
                                            $fieldReflection->setAccessible(true);
                                            $fieldReflection->setValue($joinedEntity, $fieldValue);
                                        }
                                    }
                                }

                                // Add the new entity to the array
                                $currentArray[] = $joinedEntity;

                                // Set the updated array back to the property
                                $reflection->setValue($entity, $currentArray);
                            } else {
                                // Create an instance of the target class and map properties
                                $joinedEntity = new $targetClass();

                                // Map each field to the joined entity
                                foreach ($value as $fieldName => $fieldValue) {
                                    // Try direct property matching
                                    if (property_exists($joinedEntity, $fieldName)) {
                                        $fieldReflection = new \ReflectionProperty($joinedEntity, $fieldName);
                                        $fieldReflection->setAccessible(true);
                                        $fieldReflection->setValue($joinedEntity, $fieldValue);
                                    } else {
                                        // Try snake_case to camelCase conversion
                                        $camelFieldName = $this->snakeToCamel($fieldName);
                                        if (property_exists($joinedEntity, $camelFieldName)) {
                                            $fieldReflection = new \ReflectionProperty($joinedEntity, $camelFieldName);
                                            $fieldReflection->setAccessible(true);
                                            $fieldReflection->setValue($joinedEntity, $fieldValue);
                                        }
                                    }
                                }

                                // Set the joined entity to the property
                                $reflection->setValue($entity, $joinedEntity);
                            }
                        }
                    }
                }
            }
            $entities[] = $entity;
        }

        return $entities;
    }


    /**
     * Get the target entity class for a relationship property
     */
    private function getRelationshipTargetClass(string $className, string $propertyName): ?string
    {
        try {
            $reflection = new \ReflectionClass($className);
            if (!$reflection->hasProperty($propertyName)) {
                return null;
            }

            $property = $reflection->getProperty($propertyName);
            $relationshipAttributes = $property->getAttributes(Relationship::class);

            if (empty($relationshipAttributes)) {
                return null;
            }

            /** @var Relationship $relationshipAttribute */
            $relationshipAttribute = $relationshipAttributes[0]->newInstance();
            return $relationshipAttribute->targetEntity;
        } catch (\Throwable $e) {
            // Log the error
            return null;
        }
    }

    /**
     * Helper method to convert snake_case to camelCase
     */
    private function snakeToCamel(string $input): string
    {
        return lcfirst(str_replace('_', '', ucwords($input, '_')));
    }

    /**
     * Helper method to convert camelCase to snake_case
     */
    private function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    /**
     * Get field name from property using Field attribute
     */
    private function getFieldNameFromProperty(\ReflectionProperty $property): ?string
    {
        $attributes = $property->getAttributes(\Phillarmonic\SyncopateBundle\Attribute\Field::class);
        if (empty($attributes)) {
            return null;
        }

        $fieldAttr = $attributes[0]->newInstance();
        return $fieldAttr->name;
    }
}
