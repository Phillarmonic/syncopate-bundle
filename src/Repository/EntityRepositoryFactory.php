<?php

namespace Phillarmonic\SyncopateBundle\Repository;

use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Mapper\EntityMapper;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;

class EntityRepositoryFactory
{
    private SyncopateService $syncopateService;
    private EntityMapper $entityMapper;
    private array $repositories = [];

    public function __construct(
        SyncopateService $syncopateService,
        EntityMapper $entityMapper
    ) {
        $this->syncopateService = $syncopateService;
        $this->entityMapper = $entityMapper;
    }

    /**
     * Get repository for entity class
     */
    public function getRepository(string $entityClass): EntityRepository
    {
        if (!isset($this->repositories[$entityClass])) {
            // Check if entity has a custom repository class
            $repositoryClass = $this->getRepositoryClass($entityClass);

            if ($repositoryClass && class_exists($repositoryClass) && is_subclass_of($repositoryClass, EntityRepository::class)) {
                // Create instance of custom repository
                $this->repositories[$entityClass] = new $repositoryClass(
                    $this->syncopateService,
                    $this->entityMapper,
                    $entityClass
                );
            } else {
                // Use default repository
                $this->repositories[$entityClass] = new EntityRepository(
                    $this->syncopateService,
                    $this->entityMapper,
                    $entityClass
                );
            }
        }

        return $this->repositories[$entityClass];
    }

    /**
     * Get repository class from entity attributes
     */
    private function getRepositoryClass(string $entityClass): ?string
    {
        try {
            $reflection = new \ReflectionClass($entityClass);
            $attributes = $reflection->getAttributes(Entity::class);

            if (!empty($attributes)) {
                /** @var Entity $entityAttribute */
                $entityAttribute = $attributes[0]->newInstance();
                return $entityAttribute->repositoryClass ?? null;
            }
        } catch (\ReflectionException $e) {
            // Ignore reflection errors
        }

        return null;
    }
}