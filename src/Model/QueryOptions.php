<?php

namespace Philharmonic\SyncopateBundle\Model;

class QueryOptions
{
    private string $entityType;
    private array $filters = [];
    private ?int $limit = null;
    private int $offset = 0;
    private ?string $orderBy = null;
    private bool $orderDesc = false;
    private ?array $fuzzyOpts = null;

    public function __construct(string $entityType)
    {
        $this->entityType = $entityType;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
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

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function setLimit(?int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function getOrderBy(): ?string
    {
        return $this->orderBy;
    }

    public function setOrderBy(?string $orderBy): self
    {
        $this->orderBy = $orderBy;
        return $this;
    }

    public function isOrderDesc(): bool
    {
        return $this->orderDesc;
    }

    public function setOrderDesc(bool $orderDesc): self
    {
        $this->orderDesc = $orderDesc;
        return $this;
    }

    public function getFuzzyOpts(): ?array
    {
        return $this->fuzzyOpts;
    }

    /**
     * Set fuzzy search options
     */
    public function setFuzzyOpts(float $threshold = 0.7, int $maxDistance = 3): self
    {
        $this->fuzzyOpts = [
            'threshold' => $threshold,
            'maxDistance' => $maxDistance,
        ];
        return $this;
    }

    /**
     * Convert to array for API request
     */
    public function toArray(): array
    {
        $result = [
            'entityType' => $this->entityType,
            'filters' => array_map(
                fn(QueryFilter $filter) => $filter->toArray(),
                $this->filters
            ),
            'offset' => $this->offset,
        ];

        if ($this->limit !== null) {
            $result['limit'] = $this->limit;
        }

        if ($this->orderBy !== null) {
            $result['orderBy'] = $this->orderBy;
            $result['orderDesc'] = $this->orderDesc;
        }

        if ($this->fuzzyOpts !== null) {
            $result['fuzzyOpts'] = $this->fuzzyOpts;
        }

        return $result;
    }
}