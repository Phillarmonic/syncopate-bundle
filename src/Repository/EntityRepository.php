<?php

namespace Philharmonic\SyncopateBundle\Repository;

use Philharmonic\SyncopateBundle\Mapper\EntityMapper;
use Philharmonic\SyncopateBundle\Model\QueryFilter;
use Philharmonic\SyncopateBundle\Model\QueryOptions;
use Philharmonic\SyncopateBundle\Service\SyncopateService;

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
     * Delete an entity
     */
    public function delete(object $entity): bool
    {
        if (!$entity instanceof $this->entityClass) {
            throw new \InvalidArgumentException(
                sprintf('Entity must be an instance of %s', $this->entityClass)
            );
        }

        return $this->syncopateService->delete($entity);
    }

    /**
     * Delete entity by ID
     */
    public function deleteById(string|int $id): bool
    {
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
}