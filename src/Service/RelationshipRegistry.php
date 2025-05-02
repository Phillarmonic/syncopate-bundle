<?php

namespace Phillarmonic\SyncopateBundle\Service;

use Phillarmonic\SyncopateBundle\Metadata\RelationshipMetadata;

class RelationshipRegistry
{
    private array $metadataCache = [];

    public function getRelationshipMetadata(string $entityClass): RelationshipMetadata
    {
        if (!isset($this->metadataCache[$entityClass])) {
            $this->metadataCache[$entityClass] = new RelationshipMetadata($entityClass);
        }

        return $this->metadataCache[$entityClass];
    }
}