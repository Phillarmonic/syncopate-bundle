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
    private array $entityTypeToClass = [];
    private array $classToEntityType = [];
    private array $entityPaths;
    private bool $autoCreateEntityTypes;
    private bool $cacheEntityTypes;
    private int $cacheTtl;
    private EntityMapper $entityMapper;
    private SyncopateClient $client;
    private ?CacheItemPoolInterface $cache;
    private bool $initialized = false;
    private bool $entitiesDiscovered = false;

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

    /**
     * Initialize base entity mapping information but don't load all definitions yet
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // If cache is enabled, try to load mappings from cache
        if ($this->cacheEntityTypes && $this->cache !== null) {
            $cacheItem = $this->cache->getItem('syncopate_entity_mappings');
            if ($cacheItem->isHit()) {
                $mappings = $cacheItem->get();
                $this->entityTypeToClass = $mappings['typeToClass'] ?? [];
                $this->classToEntityType = $mappings['classToType'] ?? [];
                $this->entitiesDiscovered = true;
                $this->initialized = true;
                return;
            }
        }

        $this->initialized = true;
    }

    /**
     * Get entity definition lazily
     */
    public function getEntityDefinition(string $entityType): ?EntityDefinition
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // If we already have this definition, return it
        if (isset($this->entityDefinitions[$entityType])) {
            return $this->entityDefinitions[$entityType];
        }

        // Try to load from SyncopateDB
        try {
            $data = $this->client->getEntityType($entityType);
            $definition = EntityDefinition::fromArray($data);
            $this->entityDefinitions[$entityType] = $definition;
            return $definition;
        } catch (\Throwable $e) {
            // If it doesn't exist and we have a class for it, try to create it
            if ($this->autoCreateEntityTypes && isset($this->entityTypeToClass[$entityType])) {
                return $this->createEntityTypeFromClass($this->entityTypeToClass[$entityType]);
            }
        }

        return null;
    }

    /**
     * Get entity type from class name
     */
    public function getEntityType(string $className): ?string
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // Make sure entities are discovered
        if (!$this->entitiesDiscovered) {
            $this->discoverEntityClasses();
        }

        // If we already know this class, return its entity type
        if (isset($this->classToEntityType[$className])) {
            return $this->classToEntityType[$className];
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
            $entityType = $entityAttribute->name ?? $this->getDefaultEntityName($reflection);

            // Add to our mappings
            $this->classToEntityType[$className] = $entityType;
            $this->entityTypeToClass[$entityType] = $className;

            return $entityType;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get entity class from entity type
     */
    public function getEntityClass(string $entityType): ?string
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // Make sure entities are discovered
        if (!$this->entitiesDiscovered) {
            $this->discoverEntityClasses();
        }

        return $this->entityTypeToClass[$entityType] ?? null;
    }

    /**
     * Get all entity types
     */
    public function getAllEntityTypes(): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        // Make sure entities are discovered
        if (!$this->entitiesDiscovered) {
            $this->discoverEntityClasses();
        }

        // Return just the keys from our map
        return array_keys($this->entityTypeToClass);
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
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->initialize();
        $this->discoverEntityClasses();

        // Preload common entity definitions for faster startup
        $entityTypes = array_keys($this->entityTypeToClass);
        foreach (array_slice($entityTypes, 0, 10) as $entityType) {
            $this->getEntityDefinition($entityType);
        }

        return [];
    }

    /**
     * Discover entity classes in configured paths
     */
    private function discoverEntityClasses(): void
    {
        if ($this->entitiesDiscovered) {
            return;
        }

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

                        // Add to mappings
                        $this->classToEntityType[$className] = $entityType;
                        $this->entityTypeToClass[$entityType] = $className;
                    }
                } catch (\Throwable $e) {
                    // Ignore invalid classes
                    continue;
                }
            }
        }

        // Cache entity mappings if enabled
        if ($this->cacheEntityTypes && $this->cache !== null) {
            $cacheItem = $this->cache->getItem('syncopate_entity_mappings');
            $cacheItem->set([
                'typeToClass' => $this->entityTypeToClass,
                'classToType' => $this->classToEntityType,
            ]);
            $cacheItem->expiresAfter($this->cacheTtl);
            $this->cache->save($cacheItem);
        }

        $this->entitiesDiscovered = true;
    }

    /**
     * Create an entity type in SyncopateDB
     */
    private function createEntityTypeFromClass(string $className): ?EntityDefinition
    {
        try {
            $definition = $this->entityMapper->extractEntityDefinition($className);
            $response = $this->client->createEntityType($definition->toArray());

            // Store the definition that was actually created
            if (isset($response['entityType'])) {
                $createdDefinition = EntityDefinition::fromArray($response['entityType']);
                $this->entityDefinitions[$definition->getName()] = $createdDefinition;
                return $createdDefinition;
            } else {
                $this->entityDefinitions[$definition->getName()] = $definition;
                return $definition;
            }
        } catch (SyncopateApiException $e) {
            // Log the error or handle it as needed
            return null;
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