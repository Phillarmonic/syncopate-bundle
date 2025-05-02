<?php

namespace Phillarmonic\SyncopateBundle\Metadata;

use Phillarmonic\SyncopateBundle\Attribute\Relationship;
use ReflectionClass;
use ReflectionProperty;

class RelationshipMetadata
{
    private array $relationships = [];

    public function __construct(
        private string $entityClass
    ) {
        $this->extractRelationships();
    }

    public function getRelationships(): array
    {
        return $this->relationships;
    }

    public function hasCascadeDeleteRelationships(): bool
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship['cascade'] === Relationship::CASCADE_REMOVE) {
                return true;
            }
        }

        return false;
    }

    private function extractRelationships(): void
    {
        $reflection = new ReflectionClass($this->entityClass);

        foreach ($reflection->getProperties() as $property) {
            $relationshipAttributes = $property->getAttributes(Relationship::class);

            if (empty($relationshipAttributes)) {
                continue;
            }

            /** @var Relationship $relationshipAttribute */
            $relationshipAttribute = $relationshipAttributes[0]->newInstance();

            $this->relationships[$property->getName()] = [
                'property' => $property,
                'targetEntity' => $relationshipAttribute->targetEntity,
                'type' => $relationshipAttribute->type,
                'joinColumn' => $relationshipAttribute->joinColumn,
                'joinTable' => $relationshipAttribute->joinTable,
                'mappedBy' => $relationshipAttribute->mappedBy,
                'inversedBy' => $relationshipAttribute->inversedBy,
                'cascade' => $relationshipAttribute->cascade
            ];
        }
    }
}