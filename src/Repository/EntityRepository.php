<?php

namespace Phillarmonic\SyncopateBundle\Repository;

use Phillarmonic\SyncopateBundle\Mapper\EntityMapper;
use Phillarmonic\SyncopateBundle\Model\JoinQueryOptions;
use Phillarmonic\SyncopateBundle\Model\QueryFilter;
use Phillarmonic\SyncopateBundle\Model\QueryOptions;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;

class EntityRepository
{
    protected SyncopateService $syncopateService;
    protected EntityMapper $entityMapper;
    protected string $entityClass;

    public function __construct(
        SyncopateService $syncopateService,
        EntityMapper $entityMapper,
        string $entityClass
    ) {
        $this->syncopateService = $syncopateService;
        $this->entityMapper = $entityMapper;
        $this->entityClass = $entityClass;
    }

    /**
     * Find an entity by its ID
     */
    public function find(string|int $id): ?object
    {
        try {
            return $this->syncopateService->getById($this->entityClass, $id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Find entities by criteria
     */
    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null, int $offset = 0): array
    {
        return $this->syncopateService->findBy($this->entityClass, $criteria, $orderBy, $limit, $offset);
    }

    /**
     * Find one entity by criteria
     */
    public function findOneBy(array $criteria): ?object
    {
        return $this->syncopateService->findOneBy($this->entityClass, $criteria);
    }

    /**
     * Find all entities
     */
    public function findAll(): array
    {
        return $this->syncopateService->findAll($this->entityClass);
    }

    /**
     * Count entities matching criteria
     */
    public function count(array $criteria = []): int
    {
        return $this->syncopateService->count($this->entityClass, $criteria);
    }

    /**
     * Create a new entity
     */
    public function create(object $entity): object
    {
        if (!$entity instanceof $this->entityClass) {
            throw new \InvalidArgumentException(
                sprintf('Entity must be an instance of %s', $this->entityClass)
            );
        }

        return $this->syncopateService->create($entity);
    }

    /**
     * Update an entity
     */
    public function update(object $entity): object
    {
        if (!$entity instanceof $this->entityClass) {
            throw new \InvalidArgumentException(
                sprintf('Entity must be an instance of %s', $this->entityClass)
            );
        }

        return $this->syncopateService->update($entity);
    }

    /**
     * Delete an entity with optional cascade delete
     */
    public function delete(object $entity, bool $cascade = false): bool
    {
        if (!$entity instanceof $this->entityClass) {
            throw new \InvalidArgumentException(
                sprintf('Entity must be an instance of %s', $this->entityClass)
            );
        }

        return $this->syncopateService->delete($entity, $cascade);
    }

    /**
     * Delete entity by ID with optional cascade delete
     */
    public function deleteById(string|int $id, bool $cascade = false): bool
    {
        if ($cascade) {
            // For cascade delete, we need to load the entity first
            $entity = $this->find($id);
            if ($entity === null) {
                return false;
            }

            return $this->delete($entity, true);
        }

        return $this->syncopateService->deleteById($this->entityClass, $id);
    }

    /**
     * Execute a custom query
     */
    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this->syncopateService, $this->entityClass);
    }

    /**
     * Execute a raw query with QueryOptions
     */
    public function executeQuery(QueryOptions $queryOptions): array
    {
        return $this->syncopateService->query($this->entityClass, $queryOptions);
    }

    /**
     * Create a join query builder
     */
    public function createJoinQueryBuilder(): JoinQueryBuilder
    {
        return new JoinQueryBuilder($this->syncopateService, $this->entityClass);
    }

    /**
     * Execute a join query with JoinQueryOptions
     */
    public function executeJoinQuery(JoinQueryOptions $joinQueryOptions): array
    {
        return $this->syncopateService->joinQuery($this->entityClass, $joinQueryOptions);
    }
}