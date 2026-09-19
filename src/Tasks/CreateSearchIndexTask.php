<?php

// modules/silverstripe-opensearch/src/Tasks/CreateSearchIndexTask.php

namespace AmolSW\OpenSearch\Tasks;

use AmolSW\OpenSearch\OpenSearchService;
use RuntimeException;
use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class CreateSearchIndexTask extends BuildTask
{
    protected string $title = 'Create Search Index Task';

    protected static string $description =
        'Creates the OpenSearch index with explicit field mapping if it does not already exist';

    protected static string $commandName = 'CreateSearchIndexTask';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        try {
            $indexName = OpenSearchService::getIndexName();

            if (!OpenSearchService::createIndexIfNotExists()) {
                if (Director::isDev()) {
                    // Mapping only applies at creation time; recreate in dev so
                    // mapping changes take effect
                    OpenSearchService::deleteIndex();
                    OpenSearchService::createIndexIfNotExists();
                    $output->writeln(sprintf('Dev mode: deleted and recreated index "%s" with mapping.', $indexName));
                    return Command::SUCCESS;
                }
                $output->writeln(sprintf('Index "%s" already exists. Nothing to do.', $indexName));
            } else {
                $output->writeln(sprintf('Created index "%s" with mapping.', $indexName));
            }

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            $output->writeln('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
