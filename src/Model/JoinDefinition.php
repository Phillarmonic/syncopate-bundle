<?php

namespace Phillarmonic\SyncopateBundle\Model;

class JoinDefinition
{
    public const JOIN_TYPE_INNER = 'inner';
    public const JOIN_TYPE_LEFT = 'left';

    public const SELECT_STRATEGY_FIRST = 'first';
    public const SELECT_STRATEGY_ALL = 'all';

    private string $entityType;
    private string $localField;
    private string $foreignField;
    private string $as;
    private string $type;
    private string $selectStrategy;
    private array $filters = [];
    private array $includeFields = [];
    private array $excludeFields = [];

    public function __construct(
        string $entityType,
        string $localField,
        string $foreignField,
        string $as,
        string $type = self::JOIN_TYPE_INNER,
        string $selectStrategy = self::SELECT_STRATEGY_FIRST
    ) {
        $this->entityType = $entityType;
        $this->localField = $localField;
        $this->foreignField = $foreignField;
        $this->as = $as;
        $this->type = $type;
        $this->selectStrategy = $selectStrategy;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getLocalField(): string
    {
        return $this->localField;
    }

    public function getForeignField(): string
    {
        return $this->foreignField;
    }

    public function getAs(): string
    {
        return $this->as;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSelectStrategy(): string
    {
        return $this->selectStrategy;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function addFilter(QueryFilter $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    public function setFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    public function getIncludeFields(): array
    {
        return $this->includeFields;
    }

    public function setIncludeFields(array $includeFields): self
    {
        $this->includeFields = $includeFields;
        return $this;
    }

    public function getExcludeFields(): array
    {
        return $this->excludeFields;
    }

    public function setExcludeFields(array $excludeFields): self
    {
        $this->excludeFields = $excludeFields;
        return $this;
    }

    /**
     * Convert to array for API request
     */
    public function toArray(): array
    {
        $result = [
            'entityType' => $this->entityType,
            'localField' => $this->localField,
            'foreignField' => $this->foreignField,
            'as' => $this->as,
            'type' => $this->type,
            'selectStrategy' => $this->selectStrategy
        ];

        if (!empty($this->filters)) {
            $result['filters'] = array_map(
                fn(QueryFilter $filter) => $filter->toArray(),
                $this->filters
            );
        }

        if (!empty($this->includeFields)) {
            $result['includeFields'] = $this->includeFields;
        }

        if (!empty($this->excludeFields)) {
            $result['excludeFields'] = $this->excludeFields;
        }

        return $result;
    }
}