<?php

// modules/silverstripe-opensearch/src/Jobs/SearchIndexConsistencyJob.php

namespace AmolSW\OpenSearch\Jobs;

use AmolSW\OpenSearch\OpenSearchService;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Verifies the OpenSearch index matches the database after a rebuild:
 * the set of indexed PageIds must equal the set of publishable post IDs.
 * On mismatch it re-queues SearchReindexJob with attempt+1 until the
 * attempt cap is reached, after which the job is marked broken so the
 * failure surfaces in /admin/queuedjobs.
 *
 * Never re-schedules itself: the nightly 3am schedule is owned by
 * SearchReindexJob, so a self-schedule would create duplicate chains.
 */
class SearchIndexConsistencyJob extends AbstractQueuedJob
{
    /**
     * QueuedJobs recreates jobs from descriptor data where the saved
     * constructor args come back as JSON strings, so no int type hint here.
     *
     * @param int|string $attempt attempt counter inherited from SearchReindexJob
     */
    public function __construct($attempt = 1)
    {
        $this->attempt = max(1, (int) $attempt);
    }

    public function getTitle()
    {
        return sprintf('Search index consistency check (attempt %s)', $this->attempt);
    }

    public function getSignature()
    {
        return md5(static::class . ':attempt:' . $this->attempt);
    }

    public function getJobType()
    {
        return QueuedJob::IMMEDIATE;
    }

    public function setup()
    {
        parent::setup();
        $this->totalSteps = 1;
    }

    public function process()
    {
        $indexedIds = OpenSearchService::getAllIndexedPageIds();
        $expectedIds = OpenSearchService::getPublishablePosts()->column('ID');

        $missing = array_diff($expectedIds, $indexedIds);
        $stale = array_diff($indexedIds, $expectedIds);

        if (empty($missing) && empty($stale)) {
            $this->addMessage(sprintf(
                'Consistent: %s indexed documents match publishable posts',
                count($expectedIds)
            ));
            $this->isComplete = true;

            return;
        }

        $this->addMessage(sprintf(
            'Inconsistent: %s missing [%s], %s stale [%s]',
            count($missing),
            implode(',', $missing),
            count($stale),
            implode(',', $stale)
        ));

        $nextAttempt = (int) $this->attempt + 1;

        if ($nextAttempt > SearchReindexJob::MAX_ATTEMPTS) {
            // Mark broken so the recurring failure is visible in the CMS
            // instead of looping silently every night
            throw new \RuntimeException(sprintf(
                'Search index still inconsistent after %s reindex attempts',
                SearchReindexJob::MAX_ATTEMPTS
            ));
        }

        $this->addMessage(sprintf('Re-queueing rebuild as attempt %s', $nextAttempt));
        QueuedJobService::singleton()->queueJob(new SearchReindexJob($nextAttempt));

        $this->isComplete = true;
    }
}
