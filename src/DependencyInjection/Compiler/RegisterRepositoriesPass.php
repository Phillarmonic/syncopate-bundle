<?php

namespace Phillarmonic\SyncopateBundle\DependencyInjection\Compiler;

use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Repository\EntityRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\Finder\Finder;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;
use Phillarmonic\SyncopateBundle\Mapper\EntityMapper;

/**
 * Compiler pass to automatically register repository services for entities
 */
class RegisterRepositoriesPass implements CompilerPassInterface
{
    /**
     * Process the container to find and register entity repositories
     */
    public function process(ContainerBuilder $container): void
    {
        // Get entity paths from bundle configuration
        $entityPaths = $container->getParameter('phillarmonic_syncopate.entity_paths');

        if (empty($entityPaths)) {
            return;
        }

        // Find all entity classes with repository classes
        $entityClasses = $this->findEntityClasses($entityPaths);

        foreach ($entityClasses as $entityClass) {
            $repositoryClass = $this->getRepositoryClass($entityClass);

            if ($repositoryClass &&
                !$container->hasDefinition($repositoryClass) &&
                class_exists($repositoryClass) &&
                is_subclass_of($repositoryClass, EntityRepository::class)) {

                // Register the repository as a service
                $this->registerRepository($container, $entityClass, $repositoryClass);
            }
        }
    }

    /**
     * Find all entity classes in the provided paths
     */
    private function findEntityClasses(array $paths): array
    {
        $entityClasses = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            try {
                $finder = new Finder();
                $finder->files()->in($path)->name('*.php');

                foreach ($finder as $file) {
                    $className = $this->getClassNameFromFile($file->getPathname(), $path);

                    if ($className === null) {
                        continue;
                    }

                    try {
                        $reflection = new \ReflectionClass($className);
                        $attributes = $reflection->getAttributes(Entity::class);

                        if (!empty($attributes)) {
                            $entityClasses[] = $className;
                        }
                    } catch (\ReflectionException $e) {
                        // Skip classes that can't be reflected
                        continue;
                    }
                }
            } catch (\Exception $e) {
                // Skip paths with errors
                continue;
            }
        }

        return $entityClasses;
    }

    /**
     * Extract full class name from a PHP file
     */
    private function getClassNameFromFile(string $filePath, string $basePath): ?string
    {
        // Read the file content
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Extract namespace and class name
        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = $matches[1];
        }

        if (preg_match('/class\s+([a-zA-Z0-9_]+)/', $content, $matches)) {
            $class = $matches[1];
        }

        if ($namespace === null || $class === null) {
            return null;
        }

        return $namespace . '\\' . $class;
    }

    /**
     * Get repository class from entity attributes
     */
    private function getRepositoryClass(string $entityClass): ?string
    {
        try {
            $reflection = new \ReflectionClass($entityClass);
            $attributes = $reflection->getAttributes(Entity::class);

            if (empty($attributes)) {
                return null;
            }

            /** @var Entity $entityAttribute */
            $entityAttribute = $attributes[0]->newInstance();
            return $entityAttribute->repositoryClass;
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * Register repository as a service in the container
     */
    private function registerRepository(ContainerBuilder $container, string $entityClass, string $repositoryClass): void
    {
        // Create the repository definition
        $definition = new Definition($repositoryClass);

        // Set autowiring and autoconfiguration
        $definition->setAutowired(true);
        $definition->setAutoconfigured(true);

        // Set constructor arguments
        $definition->setArgument('$syncopateService', new Reference(SyncopateService::class));
        $definition->setArgument('$entityMapper', new Reference(EntityMapper::class));
        $definition->setArgument('$entityClass', $entityClass);

        // Register the service
        $container->setDefinition($repositoryClass, $definition);

        // Add repository alias for autowiring by repository class
        $container->setAlias($repositoryClass, $repositoryClass)
            ->setPublic(true);
    }
}