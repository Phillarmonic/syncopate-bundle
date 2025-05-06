<?php

namespace Phillarmonic\SyncopateBundle\Repository;

use Phillarmonic\SyncopateBundle\Model\JoinDefinition;
use Phillarmonic\SyncopateBundle\Model\JoinQueryOptions;
use Phillarmonic\SyncopateBundle\Model\QueryFilter;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;

class JoinQueryBuilder extends QueryBuilder
{
    private array $joins = [];

    public function __construct(
        SyncopateService $syncopateService,
        string $entityClass
    ) {
        parent::__construct($syncopateService, $entityClass);
    }

    /**
     * Add an inner join
     */
    public function innerJoin(
        string $entityType,
        string $localField,
        string $foreignField,
        string $as
    ): self {
        $join = new JoinDefinition(
            $entityType,
            $localField,
            $foreignField,
            $as,
            JoinDefinition::JOIN_TYPE_INNER,
            JoinDefinition::SELECT_STRATEGY_FIRST
        );

        $this->joins[] = $join;
        return $this;
    }

    /**
     * Add a left join
     */
    public function leftJoin(
        string $entityType,
        string $localField,
        string $foreignField,
        string $as
    ): self {
        $join = new JoinDefinition(
            $entityType,
            $localField,
            $foreignField,
            $as,
            JoinDefinition::JOIN_TYPE_LEFT,
            JoinDefinition::SELECT_STRATEGY_FIRST
        );

        $this->joins[] = $join;
        return $this;
    }

    /**
     * Add an inner join that returns all matching entities
     */
    public function innerJoinAll(
        string $entityType,
        string $localField,
        string $foreignField,
        string $as
    ): self {
        $join = new JoinDefinition(
            $entityType,
            $localField,
            $foreignField,
            $as,
            JoinDefinition::JOIN_TYPE_INNER,
            JoinDefinition::SELECT_STRATEGY_ALL
        );

        $this->joins[] = $join;
        return $this;
    }

    /**
     * Add a left join that returns all matching entities
     */
    public function leftJoinAll(
        string $entityType,
        string $localField,
        string $foreignField,
        string $as
    ): self {
        $join = new JoinDefinition(
            $entityType,
            $localField,
            $foreignField,
            $as,
            JoinDefinition::JOIN_TYPE_LEFT,
            JoinDefinition::SELECT_STRATEGY_ALL
        );

        $this->joins[] = $join;
        return $this;
    }

    /**
     * Add join filter to the last added join
     */
    public function addJoinFilter(QueryFilter $filter): self
    {
        if (empty($this->joins)) {
            throw new \LogicException('Cannot add join filter: no joins defined');
        }

        $lastJoin = end($this->joins);
        $lastJoin->addFilter($filter);

        return $this;
    }

    /**
     * Specify fields to include from the last added join
     */
    public function includeJoinFields(array $fields): self
    {
        if (empty($this->joins)) {
            throw new \LogicException('Cannot set include fields: no joins defined');
        }

        $lastJoin = end($this->joins);
        $lastJoin->setIncludeFields($fields);

        return $this;
    }

    /**
     * Specify fields to exclude from the last added join
     */
    public function excludeJoinFields(array $fields): self
    {
        if (empty($this->joins)) {
            throw new \LogicException('Cannot set exclude fields: no joins defined');
        }

        $lastJoin = end($this->joins);
        $lastJoin->setExcludeFields($fields);

        return $this;
    }

    /**
     * Execute the join query and return results
     */
    public function getJoinResult(): array
    {
        // Get entity type from parent class private properties
        $entityType = $this->getEntityType();

        $joinQueryOptions = new JoinQueryOptions($entityType);

        // Add filters from parent class
        foreach ($this->getFilters() as $filter) {
            $joinQueryOptions->addFilter($filter);
        }

        // Add joins
        foreach ($this->joins as $join) {
            $joinQueryOptions->addJoin($join);
        }

        // Set pagination
        $joinQueryOptions->setOffset($this->getOffset());
        if ($this->getLimit() !== null) {
            $joinQueryOptions->setLimit($this->getLimit());
        }

        // Set ordering
        if ($this->getOrderBy() !== null) {
            $joinQueryOptions->setOrderBy($this->getOrderBy());
            $joinQueryOptions->setOrderDesc($this->isOrderDesc());
        }

        // Set fuzzy options
        $fuzzyOptions = $this->getFuzzyOptions();
        if ($fuzzyOptions !== null) {
            $joinQueryOptions->setFuzzyOpts(
                $fuzzyOptions['threshold'],
                $fuzzyOptions['maxDistance']
            );
        }

        return $this->getSyncopateService()->joinQuery($this->getEntityClass(), $joinQueryOptions);
    }

    /**
     * Get one result with joins
     */
    public function getOneOrNullJoinResult(): ?object
    {
        $this->limit(1);
        $results = $this->getJoinResult();
        return !empty($results) ? $results[0] : null;
    }

    // Helper methods to access parent private properties
    public function getEntityType(): string
    {
        $reflection = new \ReflectionProperty($this, 'entityType');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    protected function getFilters(): array
    {
        $reflection = new \ReflectionProperty($this, 'filters');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    protected function getOffset(): int
    {
        $reflection = new \ReflectionProperty($this, 'offset');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    protected function getLimit(): ?int
    {
        $reflection = new \ReflectionProperty($this, 'limit');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    protected function getOrderBy(): ?string
    {
        $reflection = new \ReflectionProperty($this, 'orderBy');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    protected function isOrderDesc(): bool
    {
        $reflection = new \ReflectionProperty($this, 'orderDesc');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    protected function getFuzzyOptions(): ?array
    {
        $reflection = new \ReflectionProperty($this, 'fuzzyOptions');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    protected function getEntityClass(): string
    {
        $reflection = new \ReflectionProperty($this, 'entityClass');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    protected function getSyncopateService(): SyncopateService
    {
        $reflection = new \ReflectionProperty($this, 'syncopateService');
        $reflection->setAccessible(true);
        return $reflection->getValue($this);
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        // Get an entity type from parent class private properties
        $entityType = $this->getEntityType();

        $joinQueryOptions = new JoinQueryOptions($entityType);

        // Add filters from the parent class
        foreach ($this->getFilters() as $filter) {
            $joinQueryOptions->addFilter($filter);
        }

        // Add joins
        foreach ($this->joins as $join) {
            $joinQueryOptions->addJoin($join);
        }

        // Pass to SyncopateService for count
        return $this->getSyncopateService()->countJoin($this->getEntityClass(), $joinQueryOptions);
    }
}