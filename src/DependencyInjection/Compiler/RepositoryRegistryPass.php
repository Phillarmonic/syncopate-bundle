<?php

namespace Phillarmonic\SyncopateBundle\DependencyInjection\Compiler;

use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Service\RepositoryRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Build the repository registry with mappings between entities and repositories
 */
class RepositoryRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        // Create the repository registry service if it doesn't exist
        if (!$container->hasDefinition(RepositoryRegistry::class)) {
            $registryDef = new Definition(RepositoryRegistry::class);
            $registryDef->setAutowired(true);
            $container->setDefinition(RepositoryRegistry::class, $registryDef);
        }

        // Get entity paths from bundle configuration
        $entityPaths = $container->getParameter('phillarmonic_syncopate.entity_paths');

        if (empty($entityPaths)) {
            return;
        }

        // Map of entity classes to repository classes
        $entityRepoMap = [];

        // Find all entity classes with repository classes
        $entityClasses = $this->findEntityClasses($entityPaths);

        foreach ($entityClasses as $entityClass) {
            $repositoryClass = $this->getRepositoryClass($entityClass);

            if ($repositoryClass) {
                $entityRepoMap[$entityClass] = $repositoryClass;

                // Ensure the repository service has correct $entityClass
                if ($container->hasDefinition($repositoryClass)) {
                    $container->getDefinition($repositoryClass)
                        ->setArgument('$entityClass', $entityClass);
                }
            }
        }

        // Configure services for the found repositories
        foreach ($entityRepoMap as $entityClass => $repositoryClass) {
            // Add to the repository registry
            $container->getDefinition(RepositoryRegistry::class)
                ->addMethodCall('registerMapping', [$entityClass, $repositoryClass]);

            // Create the repository definition if it doesn't exist
            if (!$container->hasDefinition($repositoryClass) && class_exists($repositoryClass)) {
                $definition = new Definition($repositoryClass);
                $definition->setAutowired(true);
                $definition->setAutoconfigured(true);
                $definition->setArgument('$entityClass', $entityClass);
                $definition->addTag('syncopate.repository', ['entity_class' => $entityClass]);

                $container->setDefinition($repositoryClass, $definition);

                // Add alias for autowiring
                if (!$container->hasAlias($repositoryClass)) {
                    $container->setAlias($repositoryClass, $repositoryClass)
                        ->setPublic(true);
                }
            }
        }
    }

    /**
     * Find all entity classes in the provided paths
     */
    private function findEntityClasses(array $paths): array
    {
        // Implementation the same as in RegisterRepositoriesPass
        // ... (code omitted for brevity) ...
        $entityClasses = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            try {
                $finder = new \Symfony\Component\Finder\Finder();
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
        // Implementation the same as in RegisterRepositoriesPass
        // ... (code omitted for brevity) ...
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
}