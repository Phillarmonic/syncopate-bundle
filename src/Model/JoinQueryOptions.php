<?php

namespace Phillarmonic\SyncopateBundle\Model;

class JoinQueryOptions extends QueryOptions
{
    private array $joins = [];

    public function __construct(string $entityType)
    {
        parent::__construct($entityType);
    }

    public function getJoins(): array
    {
        return $this->joins;
    }

    public function addJoin(JoinDefinition $join): self
    {
        $this->joins[] = $join;
        return $this;
    }

    public function setJoins(array $joins): self
    {
        $this->joins = $joins;
        return $this;
    }

    /**
     * Convert to array for API request
     */
    public function toArray(): array
    {
        $result = parent::toArray();

        if (!empty($this->joins)) {
            $result['joins'] = array_map(
                fn(JoinDefinition $join) => $join->toArray(),
                $this->joins
            );
        }

        return $result;
    }
}