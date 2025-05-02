<?php

namespace Phillarmonic\SyncopateBundle\Command;

use Phillarmonic\SyncopateBundle\Service\EntityTypeRegistry;
use Phillarmonic\SyncopateBundle\Mapper\EntityMapper;
use Phillarmonic\SyncopateBundle\Client\SyncopateClient;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use ReflectionClass;
use Phillarmonic\SyncopateBundle\Attribute\Entity;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'syncopate:register-entity-types',
    description: 'Register entity types in Syncopate',
)]
class RegisterEntityTypesCommand extends Command
{
    private EntityTypeRegistry $entityTypeRegistry;
    private EntityMapper $entityMapper;
    private SyncopateClient $client;
    private SyncopateService $syncopateService;
    private ParameterBagInterface $parameterBag;

    public function __construct(
        EntityTypeRegistry $entityTypeRegistry,
        EntityMapper $entityMapper,
        SyncopateClient $client,
        SyncopateService $syncopateService,
        ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
        $this->entityTypeRegistry = $entityTypeRegistry;
        $this->entityMapper = $entityMapper;
        $this->client = $client;
        $this->syncopateService = $syncopateService;
        $this->parameterBag = $parameterBag;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force update of entity types even if they already exist'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Additional paths to scan for entity classes'
            )
            ->addOption(
                'class',
                'c',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Specific entity classes to register'
            )
            ->addOption(
                'memory-limit',
                'm',
                InputOption::VALUE_REQUIRED,
                'Memory limit for the process in MB (default: 128)',
                128
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Set memory limit
        $memoryLimit = $input->getOption('memory-limit');
        ini_set('memory_limit', $memoryLimit . 'M');

        $io = new SymfonyStyle($input, $output);
        $io->title('SyncopateDB Entity Type Registration');
        $io->comment("Memory limit set to {$memoryLimit}MB");

        // Check connection to SyncopateDB
        try {
            $health = $this->client->health();
            $io->success('Successfully connected to SyncopateDB');
        } catch (\Throwable $e) {
            $io->error('Failed to connect to SyncopateDB: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $force = $input->getOption('force');
        $additionalPaths = $input->getOption('path');
        $specificClasses = $input->getOption('class');

        // Get existing entity types from SyncopateDB
        try {
            $existingEntityTypes = $this->client->getEntityTypes();
            $io->info(sprintf('Found %d existing entity types in SyncopateDB', count($existingEntityTypes)));
        } catch (\Throwable $e) {
            $io->error('Failed to retrieve entity types from SyncopateDB: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Get entity classes from registry or specified paths
        $entityClasses = [];

        // If specific classes were provided, use them
        if (!empty($specificClasses)) {
            foreach ($specificClasses as $className) {
                if (!class_exists($className)) {
                    $io->warning(sprintf('Class %s does not exist. Skipping.', $className));
                    continue;
                }

                try {
                    $reflection = new ReflectionClass($className);
                    $attributes = $reflection->getAttributes(Entity::class);

                    if (empty($attributes)) {
                        $io->warning(sprintf('Class %s is not marked with #[Entity] attribute. Skipping.', $className));
                        continue;
                    }

                    $entityClasses[] = $className;
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Error processing class %s: %s', $className, $e->getMessage()));
                }
            }
        } else {
            // Use registry's existing entity classes and scan additional paths
            $allPaths = array_merge(
                $this->getEntityPaths(),
                $additionalPaths
            );

            $io->info(sprintf('Scanning %d paths for entity classes', count($allPaths)));

            foreach ($allPaths as $path) {
                if (!is_dir($path)) {
                    $io->warning(sprintf('Path %s is not a directory. Skipping.', $path));
                    continue;
                }

                $finder = new Finder();
                $finder->files()->in($path)->name('*.php');

                // Process files in smaller batches
                $batch = [];
                $batchSize = 0;
                $maxBatchSize = 50;

                foreach ($finder as $file) {
                    $className = $this->getClassNameFromFile($file->getPathname(), $path);

                    if ($className === null) {
                        continue;
                    }

                    try {
                        $reflection = new ReflectionClass($className);
                        $attributes = $reflection->getAttributes(Entity::class);

                        if (!empty($attributes)) {
                            $entityClasses[] = $className;
                        }
                    } catch (\Throwable $e) {
                        // Ignore invalid classes
                    }

                    $batchSize++;

                    // Free memory periodically
                    if ($batchSize >= $maxBatchSize) {
                        gc_collect_cycles();
                        $batchSize = 0;
                    }
                }
            }
        }

        $entityClasses = array_unique($entityClasses);
        $io->info(sprintf('Found %d entity classes to process', count($entityClasses)));

        if (empty($entityClasses)) {
            $io->warning('No entity classes found. Nothing to register.');
            return Command::SUCCESS;
        }

        // Register each entity type
        $registered = 0;
        $updated = 0;
        $failed = 0;
        $skipped = 0;

        $progressBar = $io->createProgressBar(count($entityClasses));
        $progressBar->start();

        // Process in batches to manage memory
        $batchSize = 10;
        $batches = array_chunk($entityClasses, $batchSize);

        foreach ($batches as $batch) {
            foreach ($batch as $className) {
                $progressBar->advance();

                try {
                    $entityDefinition = $this->entityMapper->extractEntityDefinition($className);
                    $entityType = $entityDefinition->getName();

                    $exists = in_array($entityType, $existingEntityTypes);

                    if ($exists && !$force) {
                        // Skip existing entity types unless force option is used
                        $skipped++;
                        continue;
                    }

                    if ($exists) {
                        // Update existing entity type
                        $this->client->updateEntityType($entityType, $entityDefinition->toArray());
                        $updated++;
                    } else {
                        // Create new entity type
                        $this->client->createEntityType($entityDefinition->toArray());
                        $registered++;
                    }

                } catch (\Throwable $e) {
                    $io->newLine();
                    $io->error(sprintf('Failed to register entity type for class %s: %s', $className, $e->getMessage()));
                    $failed++;
                }
            }

            // Free memory after each batch
            gc_collect_cycles();
        }

        $progressBar->finish();
        $io->newLine(2);

        // Display summary
        $io->success([
            sprintf('Entity type registration complete.'),
            sprintf('Registered: %d', $registered),
            sprintf('Updated: %d', $updated),
            sprintf('Skipped: %d', $skipped),
            sprintf('Failed: %d', $failed)
        ]);

        // Output memory usage
        $memUsed = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $io->comment("Peak memory usage: {$memUsed}MB");

        return Command::SUCCESS;
    }

    /**
     * Get entity paths from the parameter bag instead of using reflection
     */
    private function getEntityPaths(): array
    {
        return $this->parameterBag->get('phillarmonic_syncopate.entity_paths');
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
}