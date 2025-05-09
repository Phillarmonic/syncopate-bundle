<?php

namespace Phillarmonic\SyncopateBundle\Command;

use Phillarmonic\SyncopateBundle\Service\SyncopateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\Question;

#[AsCommand(
    name: 'syncopate:truncate:database',
    description: 'Truncate the entire SyncopateDB database (all entity types)',
)]
class TruncateDatabaseCommand extends Command
{
    private SyncopateService $syncopateService;

    public function __construct(
        SyncopateService $syncopateService
    ) {
        parent::__construct();
        $this->syncopateService = $syncopateService;
    }

    protected function configure(): void
    {
        $this
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
        $io->title('SyncopateDB Database Truncation');
        $io->caution([
            'WARNING: This operation will delete ALL entities from ALL entity types in the database.',
            'This action cannot be undone!'
        ]);

        // Check connection to SyncopateDB
        try {
            $this->syncopateService->checkHealth();
            $io->success('Successfully connected to SyncopateDB');
        } catch (\Throwable $e) {
            $io->error('Failed to connect to SyncopateDB: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Get entity types to show how many will be affected
        try {
            $entityTypes = $this->syncopateService->getAllEntityTypes();
            $io->info(sprintf('This will truncate %d entity types: %s',
                count($entityTypes),
                implode(', ', $entityTypes)
            ));
        } catch (\Throwable $e) {
            $io->warning('Could not retrieve entity types: ' . $e->getMessage());
        }

        // Confirm truncation unless --force is used
        $force = $input->getOption('force');
        if (!$force) {
            $io->newLine();
            $io->warning([
                'To confirm truncation, please type "TRUNCATE" (all uppercase)',
                'This is to prevent accidental data loss'
            ]);

            $helper = $this->getHelper('question');
            $question = new Question('Confirmation: ');
            $confirmation = $helper->ask($input, $output, $question);

            if ($confirmation !== 'TRUNCATE') {
                $io->warning('Operation cancelled - confirmation did not match "TRUNCATE".');
                return Command::SUCCESS;
            }
        }

        // Execute database truncation
        try {
            $io->section('Truncating entire database...');
            $result = $this->syncopateService->truncateDatabase();

            if (isset($result['message'])) {
                $io->success([
                    $result['message'],
                    sprintf('Entities removed: %d', $result['entities_removed']),
                    sprintf('Entity types truncated: %d', $result['entity_types_truncated'] ?? 0)
                ]);
                return Command::SUCCESS;
            } else {
                $io->error('Database truncation operation failed.');
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $io->error('Error during database truncation: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}