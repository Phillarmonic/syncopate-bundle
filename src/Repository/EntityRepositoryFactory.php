<?php

namespace Phillarmonic\SyncopateBundle\Repository;

use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Mapper\EntityMapper;
use Phillarmonic\SyncopateBundle\Service\RepositoryRegistry;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class EntityRepositoryFactory
{
    private SyncopateService $syncopateService;
    private EntityMapper $entityMapper;
    private RepositoryRegistry $repositoryRegistry;
    private ContainerInterface $container;
    private array $repositories = [];

    public function __construct(
        SyncopateService $syncopateService,
        EntityMapper $entityMapper,
        RepositoryRegistry $repositoryRegistry,
        ContainerInterface $container
    ) {
        $this->syncopateService = $syncopateService;
        $this->entityMapper = $entityMapper;
        $this->repositoryRegistry = $repositoryRegistry;
        $this->container = $container;
    }

    /**
     * Get repository for entity class
     */
    public function getRepository(string $entityClass): EntityRepository
    {
        if (!isset($this->repositories[$entityClass])) {
            // First, try to get repository from container if it exists
            $repositoryClass = $this->repositoryRegistry->getRepositoryClassForEntity($entityClass)
                ?? $this->getRepositoryClass($entityClass);

            if ($repositoryClass && $this->container->has($repositoryClass)) {
                // Use the repository from the container
                $this->repositories[$entityClass] = $this->container->get($repositoryClass);
            } else if ($repositoryClass && class_exists($repositoryClass) && is_subclass_of($repositoryClass, EntityRepository::class)) {
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
        // Try from registry first
        $repositoryClass = $this->repositoryRegistry->discoverRepositoryClass($entityClass);
        if ($repositoryClass) {
            return $repositoryClass;
        }

        // Fall back to reflection
        try {
            $reflection = new \ReflectionClass($entityClass);
            $attributes = $reflection->getAttributes(Entity::class);

            if (!empty($attributes)) {
                /** @var Entity $entityAttribute */
                $entityAttribute = $attributes[0]->newInstance();
                $repoClass = $entityAttribute->repositoryClass ?? null;

                // Register in registry if found
                if ($repoClass) {
                    $this->repositoryRegistry->registerMapping($entityClass, $repoClass);
                }

                return $repoClass;
            }
        } catch (\ReflectionException $e) {
            // Ignore reflection errors
        }

        return null;
    }
}