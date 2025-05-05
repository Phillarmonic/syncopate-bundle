<?php

namespace Phillarmonic\SyncopateBundle\Service;

use Phillarmonic\SyncopateBundle\Attribute\Entity;

/**
 * Registry that maps repository classes to entity classes
 */
class RepositoryRegistry
{
    /**
     * Map of repository class names to entity class names
     */
    private array $repositoryToEntityMap = [];

    /**
     * Map of entity class names to repository class names
     */
    private array $entityToRepositoryMap = [];

    /**
     * Register a mapping between an entity class and its repository class
     */
    public function registerMapping(string $entityClass, string $repositoryClass): void
    {
        $this->repositoryToEntityMap[$repositoryClass] = $entityClass;
        $this->entityToRepositoryMap[$entityClass] = $repositoryClass;
    }

    /**
     * Get entity class for a repository class
     */
    public function getEntityClassForRepository(string $repositoryClass): ?string
    {
        return $this->repositoryToEntityMap[$repositoryClass] ?? null;
    }

    /**
     * Get repository class for an entity class
     */
    public function getRepositoryClassForEntity(string $entityClass): ?string
    {
        return $this->entityToRepositoryMap[$entityClass] ?? null;
    }

    /**
     * Discover repository mapping for entity class
     */
    public function discoverRepositoryClass(string $entityClass): ?string
    {
        // Check if already in map
        if (isset($this->entityToRepositoryMap[$entityClass])) {
            return $this->entityToRepositoryMap[$entityClass];
        }

        // Try to discover from entity attributes
        try {
            $reflection = new \ReflectionClass($entityClass);
            $attributes = $reflection->getAttributes(Entity::class);

            if (!empty($attributes)) {
                /** @var Entity $entityAttribute */
                $entityAttribute = $attributes[0]->newInstance();
                if ($entityAttribute->repositoryClass) {
                    $this->registerMapping($entityClass, $entityAttribute->repositoryClass);
                    return $entityAttribute->repositoryClass;
                }
            }
        } catch (\Throwable $e) {
            // Ignore reflection errors
        }

        return null;
    }
}