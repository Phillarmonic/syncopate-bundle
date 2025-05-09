<?php

namespace Phillarmonic\SyncopateBundle\Command;

use Phillarmonic\SyncopateBundle\Service\EntityTypeRegistry;
use Phillarmonic\SyncopateBundle\Service\SyncopateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ChoiceQuestion;

#[AsCommand(
    name: 'syncopate:truncate:entity',
    description: 'Truncate all entities of a specific entity type',
)]
class TruncateEntityCommand extends Command
{
    private EntityTypeRegistry $entityTypeRegistry;
    private SyncopateService $syncopateService;

    public function __construct(
        EntityTypeRegistry $entityTypeRegistry,
        SyncopateService $syncopateService
    ) {
        parent::__construct();
        $this->entityTypeRegistry = $entityTypeRegistry;
        $this->syncopateService = $syncopateService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'entity-type',
                InputArgument::OPTIONAL,
                'The entity type to truncate (e.g., product)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip confirmation prompt'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SyncopateDB Entity Truncation');

        // Check connection to SyncopateDB
        try {
            $this->syncopateService->checkHealth();
            $io->success('Successfully connected to SyncopateDB');
        } catch (\Throwable $e) {
            $io->error('Failed to connect to SyncopateDB: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Get entity type from input or prompt for one
        $entityType = $input->getArgument('entity-type');

        if (!$entityType) {
            try {
                // Fetch available entity types from SyncopateDB
                $entityTypes = $this->syncopateService->getAllEntityTypes();

                if (empty($entityTypes)) {
                    $io->warning('No entity types found in SyncopateDB.');
                    return Command::SUCCESS;
                }

                // Sort entity types alphabetically for better UX
                sort($entityTypes);

                // Create a question with choices
                $helper = $this->getHelper('question');
                $question = new ChoiceQuestion(
                    'Please select the entity type to truncate:',
                    $entityTypes
                );
                $question->setErrorMessage('Entity type %s is invalid.');

                $entityType = $helper->ask($input, $output, $question);
                $io->info(sprintf('Selected entity type: %s', $entityType));

            } catch (\Throwable $e) {
                $io->error('Failed to retrieve entity types: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Find the entity class for the given entity type
        $entityClass = $this->entityTypeRegistry->getEntityClass($entityType);

        if (!$entityClass) {
            $io->error(sprintf('Entity type "%s" not found in the registry.', $entityType));
            return Command::FAILURE;
        }

        // Confirm truncation unless --force is used
        $force = $input->getOption('force');
        if (!$force && !$io->confirm(
            sprintf('Are you sure you want to truncate ALL entities of type "%s"? This operation cannot be undone!', $entityType),
            false
        )) {
            $io->warning('Operation cancelled.');
            return Command::SUCCESS;
        }

        // Execute truncation
        try {
            $io->section(sprintf('Truncating entity type "%s"...', $entityType));

            $success = $this->syncopateService->truncateEntityType($entityClass);

            if ($success) {
                $io->success(sprintf('Successfully truncated all entities of type "%s"', $entityType));
                return Command::SUCCESS;
            } else {
                $io->error('Truncation operation failed.');
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $io->error('Error during truncation: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}