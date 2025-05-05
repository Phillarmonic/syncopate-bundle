<?php

namespace Phillarmonic\SyncopateBundle\Repository;

use Phillarmonic\SyncopateBundle\Model\QueryFilter;
use Phillarmonic\SyncopateBundle\Model\QueryOptions;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;

class QueryBuilder
{
    private SyncopateService $syncopateService;
    private string $entityClass;
    protected string $entityType;
    private array $filters = [];
    private ?string $orderBy = null;
    private bool $orderDesc = false;
    private ?int $limit = null;
    private int $offset = 0;
    private ?array $fuzzyOptions = null;

    public function __construct(
        SyncopateService $syncopateService,
        string $entityClass
    ) {
        $this->syncopateService = $syncopateService;
        $this->entityClass = $entityClass;

        // Get entity type from service
        $reflection = new \ReflectionProperty($syncopateService, 'entityTypeRegistry');
        $reflection->setAccessible(true);
        $entityTypeRegistry = $reflection->getValue($syncopateService);
        $this->entityType = $entityTypeRegistry->getEntityType($entityClass);

        if ($this->entityType === null) {
            throw new \InvalidArgumentException("Class $entityClass is not registered as an entity");
        }
    }

    /**
     * Add an equals condition
     */
    public function eq(string $field, mixed $value): self
    {
        $this->filters[] = QueryFilter::eq($field, $value);
        return $this;
    }

    /**
     * Add a not equals condition
     */
    public function neq(string $field, mixed $value): self
    {
        $this->filters[] = QueryFilter::neq($field, $value);
        return $this;
    }

    /**
     * Add a greater than condition
     */
    public function gt(string $field, mixed $value): self
    {
        $this->filters[] = QueryFilter::gt($field, $value);
        return $this;
    }

    /**
     * Add a greater than or equals condition
     */
    public function gte(string $field, mixed $value): self
    {
        $this->filters[] = QueryFilter::gte($field, $value);
        return $this;
    }

    /**
     * Add a less than condition
     */
    public function lt(string $field, mixed $value): self
    {
        $this->filters[] = QueryFilter::lt($field, $value);
        return $this;
    }

    /**
     * Add a less than or equals condition
     */
    public function lte(string $field, mixed $value): self
    {
        $this->filters[] = QueryFilter::lte($field, $value);
        return $this;
    }

    /**
     * Add a contains condition
     */
    public function contains(string $field, string $value): self
    {
        $this->filters[] = QueryFilter::contains($field, $value);
        return $this;
    }

    /**
     * Add a starts with condition
     */
    public function startsWith(string $field, string $value): self
    {
        $this->filters[] = QueryFilter::startsWith($field, $value);
        return $this;
    }

    /**
     * Add an ends with condition
     */
    public function endsWith(string $field, string $value): self
    {
        $this->filters[] = QueryFilter::endsWith($field, $value);
        return $this;
    }

    /**
     * Add an in condition
     */
    public function in(string $field, array $values): self
    {
        $this->filters[] = QueryFilter::in($field, $values);
        return $this;
    }

    /**
     * Add a fuzzy search condition
     */
    public function fuzzy(string $field, string $value): self
    {
        $this->filters[] = QueryFilter::fuzzy($field, $value);
        return $this;
    }

    /**
     * Add an array contains condition
     */
    public function arrayContains(string $field, mixed $value): self
    {
        $this->filters[] = QueryFilter::arrayContains($field, $value);
        return $this;
    }

    /**
     * Add an array contains any condition
     */
    public function arrayContainsAny(string $field, array $values): self
    {
        $this->filters[] = QueryFilter::arrayContainsAny($field, $values);
        return $this;
    }

    /**
     * Add an array contains all condition
     */
    public function arrayContainsAll(string $field, array $values): self
    {
        $this->filters[] = QueryFilter::arrayContainsAll($field, $values);
        return $this;
    }

    /**
     * Set ordering
     */
    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $this->orderBy = $field;
        $this->orderDesc = strtoupper($direction) === 'DESC';
        return $this;
    }

    /**
     * Set limit
     */
    public function limit(?int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set offset
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Set fuzzy search options
     */
    public function setFuzzyOptions(float $threshold = 0.7, int $maxDistance = 3): self
    {
        $this->fuzzyOptions = [
            'threshold' => $threshold,
            'maxDistance' => $maxDistance,
        ];
        return $this;
    }

    /**
     * Execute the query and return results
     */
    public function getResult(): array
    {
        $queryOptions = new QueryOptions($this->entityType);

        // Add filters
        foreach ($this->filters as $filter) {
            $queryOptions->addFilter($filter);
        }

        // Set pagination
        $queryOptions->setOffset($this->offset);
        if ($this->limit !== null) {
            $queryOptions->setLimit($this->limit);
        }

        // Set ordering
        if ($this->orderBy !== null) {
            $queryOptions->setOrderBy($this->orderBy);
            $queryOptions->setOrderDesc($this->orderDesc);
        }

        // Set fuzzy options
        if ($this->fuzzyOptions !== null) {
            $queryOptions->setFuzzyOpts(
                $this->fuzzyOptions['threshold'],
                $this->fuzzyOptions['maxDistance']
            );
        }

        return $this->syncopateService->query($this->entityClass, $queryOptions);
    }

    /**
     * Execute the query and return a single result
     */
    public function getOneOrNullResult(): ?object
    {
        $this->limit(1);
        $results = $this->getResult();
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Get the entity type for this query builder
     */
    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /**
     * Count results
     */
    public function count(): int
    {
        $queryOptions = new QueryOptions($this->entityType);

        // Add filters
        foreach ($this->filters as $filter) {
            $queryOptions->addFilter($filter);
        }

        // Set the limit to 0 to just get count
        $queryOptions->setLimit(0);

        $results = $this->syncopateService->query($this->entityClass, $queryOptions);
        return $results['total'] ?? 0;
    }
}