<?php

// modules/silverstripe-opensearch/src/Tasks/CreateSearchReindexJobTask.php

namespace AmolSW\OpenSearch\Tasks;

use AmolSW\OpenSearch\Jobs\SearchReindexJob;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Seeds the recurring nightly SearchReindexJob (3am daily). Safe to run
 * repeatedly — the queuedjobs signature check deduplicates pending jobs.
 */
class CreateSearchReindexJobTask extends BuildTask
{
    protected string $title = 'Create Search Reindex Job';

    protected static string $description = 'Queues the recurring 3am nightly OpenSearch reindex job.';

    protected static string $commandName = 'CreateSearchReindexJobTask';

    public function execute(InputInterface $input, PolyOutput $output): int
    {
        SearchReindexJob::queueNextNightlyRun();
        $output->writeln('Queued nightly SearchReindexJob for 3am.');

        return Command::SUCCESS;
    }
}
