<?php

namespace Phillarmonic\SyncopateBundle\Service;

use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Phillarmonic\SyncopateBundle\Client\SyncopateClient;
use Phillarmonic\SyncopateBundle\Exception\SyncopateApiException;
use Phillarmonic\SyncopateBundle\Mapper\EntityMapper;
use Phillarmonic\SyncopateBundle\Model\EntityDefinition;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class EntityTypeRegistry implements CacheWarmerInterface
{
    private array $entityClasses = [];
    private array $entityDefinitions = [];
    private array $entityPaths;
    private bool $autoCreateEntityTypes;
    private bool $cacheEntityTypes;
    private int $cacheTtl;
    private EntityMapper $entityMapper;
    private SyncopateClient $client;
    private ?CacheItemPoolInterface $cache;
    private bool $initialized = false;

    public function __construct(
        array $entityPaths,
        bool $autoCreateEntityTypes,
        bool $cacheEntityTypes,
        int $cacheTtl,
        EntityMapper $entityMapper,
        SyncopateClient $client,
        ?CacheItemPoolInterface $cache = null
    ) {
        $this->entityPaths = $entityPaths;
        $this->autoCreateEntityTypes = $autoCreateEntityTypes;
        $this->cacheEntityTypes = $cacheEntityTypes;
        $this->cacheTtl = $cacheTtl;
        $this->entityMapper = $entityMapper;
        $this->client = $client;
        $this->cache = $cache;
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Find all entity classes
        $this->discoverEntityClasses();

        // If cache is enabled, try to load from cache
        if ($this->cacheEntityTypes && $this->cache !== null) {
            $cacheItem = $this->cache->getItem('syncopate_entity_definitions');
            if ($cacheItem->isHit()) {
                $this->entityDefinitions = $cacheItem->get();
                $this->initialized = true;
                return;
            }
        }

        // Load entity type definitions from SyncopateDB or create them if needed
        $this->loadOrCreateEntityDefinitions();

        // Cache entity definitions if enabled
        if ($this->cacheEntityTypes && $this->cache !== null) {
            $cacheItem = $this->cache->getItem('syncopate_entity_definitions');
            $cacheItem->set($this->entityDefinitions);
            $cacheItem->expiresAfter($this->cacheTtl);
            $this->cache->save($cacheItem);
        }

        $this->initialized = true;
    }

    public function getEntityDefinition(string $entityType): ?EntityDefinition
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->entityDefinitions[$entityType] ?? null;
    }

    public function getEntityType(string $className): ?string
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // If we already have this class, return its entity type
        foreach ($this->entityClasses as $entityType => $class) {
            if ($class === $className) {
                return $entityType;
            }
        }

        // If not found, try to extract from the class
        try {
            $reflection = new \ReflectionClass($className);
            $attributes = $reflection->getAttributes(Entity::class);
            if (empty($attributes)) {
                return null;
            }

            /** @var Entity $entityAttribute */
            $entityAttribute = $attributes[0]->newInstance();
            return $entityAttribute->name ?? $this->getDefaultEntityName($reflection);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getEntityClass(string $entityType): ?string
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->entityClasses[$entityType] ?? null;
    }

    public function getAllEntityTypes(): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return array_keys($this->entityClasses);
    }

    /**
     * {@inheritdoc}
     */
    public function isOptional(): bool
    {
        return true; // The cache warmer is optional
    }

    /**
     * {@inheritdoc}
     */
    public function warmUp(string $cacheDir): array
    {
        $this->initialize();
        return [];
    }

    /**
     * Discover entity classes in configured paths
     */
    private function discoverEntityClasses(): void
    {
        foreach ($this->entityPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

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
                        /** @var Entity $entityAttribute */
                        $entityAttribute = $attributes[0]->newInstance();
                        $entityType = $entityAttribute->name ?? $this->getDefaultEntityName($reflection);
                        $this->entityClasses[$entityType] = $className;
                    }
                } catch (\Throwable $e) {
                    // Ignore invalid classes
                    continue;
                }
            }
        }
    }

    /**
     * Load entity definitions from SyncopateDB or create them if needed
     */
    private function loadOrCreateEntityDefinitions(): void
    {
        // First, try to get all entity types from SyncopateDB
        try {
            $entityTypes = $this->client->getEntityTypes();

            // Create a map of entity type names to their definitions
            $existingEntityTypes = [];
            foreach ($entityTypes as $entityType) {
                try {
                    $definition = $this->client->getEntityType($entityType);
                    $existingEntityTypes[$entityType] = EntityDefinition::fromArray($definition);
                } catch (\Throwable $e) {
                    // Skip if we can't load the definition
                    continue;
                }
            }

            // Check each of our entity classes
            foreach ($this->entityClasses as $entityType => $className) {
                if (isset($existingEntityTypes[$entityType])) {
                    // Entity type already exists in SyncopateDB
                    $this->entityDefinitions[$entityType] = $existingEntityTypes[$entityType];
                } elseif ($this->autoCreateEntityTypes) {
                    // Entity type doesn't exist, create it
                    $this->createEntityType($className);
                }
            }
        } catch (\Throwable $e) {
            // If we can't connect to SyncopateDB, extract definitions locally
            foreach ($this->entityClasses as $entityType => $className) {
                try {
                    $definition = $this->entityMapper->extractEntityDefinition($className);
                    $this->entityDefinitions[$entityType] = $definition;
                } catch (\Throwable $innerE) {
                    // Skip if we can't extract the definition
                    continue;
                }
            }
        }
    }

    /**
     * Create an entity type in SyncopateDB
     */
    private function createEntityType(string $className): void
    {
        try {
            $definition = $this->entityMapper->extractEntityDefinition($className);
            $response = $this->client->createEntityType($definition->toArray());

            // Store the definition that was actually created
            if (isset($response['entityType'])) {
                $this->entityDefinitions[$definition->getName()] = EntityDefinition::fromArray($response['entityType']);
            } else {
                $this->entityDefinitions[$definition->getName()] = $definition;
            }
        } catch (SyncopateApiException $e) {
            // Log the error or handle it as needed
            // For now, we'll just skip creating this entity type
        }
    }

    /**
     * Get class name from file path
     */
    private function getClassNameFromFile(string $filePath, string $basePath): ?string
    {
        // Read the file
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
     * Get default entity name from class name
     */
    private function getDefaultEntityName(\ReflectionClass $reflection): string
    {
        $shortName = $reflection->getShortName();
        // Convert CamelCase to snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }
}