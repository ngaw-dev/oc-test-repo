<?php

// modules/silverstripe-opensearch/src/Tasks/IndexSearchDocumentsTask.php

namespace AmolSW\OpenSearch\Tasks;

use AmolSW\OpenSearch\OpenSearchService;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class IndexSearchDocumentsTask extends BuildTask
{
    protected string $title = 'Index Search Documents Task';

    protected static string $description =
        'Bulk indexes all published BlogPosts into the OpenSearch searchindex index';

    protected static string $commandName = 'IndexSearchDocumentsTask';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        try {
            $result = OpenSearchService::reindexAllPosts();
        } catch (RuntimeException $e) {
            $output->writeln('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Indexed %d document(s) into "%s".',
            $result['indexed'],
            OpenSearchService::getIndexName()
        ));

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $output->writeln(sprintf(
                    'Error indexing doc %s: %s',
                    $error['_id'] ?? '?',
                    $error['error']['type'] ?? 'unknown'
                ));
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
