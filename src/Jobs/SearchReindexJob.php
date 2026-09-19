<?php

// modules/silverstripe-opensearch/src/Jobs/SearchReindexJob.php

namespace AmolSW\OpenSearch\Jobs;

use AmolSW\OpenSearch\OpenSearchService;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Nightly full rebuild of the OpenSearch index: delete every document,
 * then re-index all published posts. Chains SearchIndexConsistencyJob on
 * completion; on mismatch the consistency job re-queues this job with an
 * incremented attempt counter (capped) — rinse and repeat.
 */
class SearchReindexJob extends AbstractQueuedJob
{
    /**
     * Hour of day the recurring job fires (3am).
     */
    public const RUN_HOUR = 3;

    /**
     * Max reindex attempts per night before the job is marked broken.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * QueuedJobs recreates jobs from descriptor data where the saved
     * constructor args come back as JSON strings, so no int type hint here.
     *
     * @param int|string $attempt 1-based attempt counter for the retry loop
     */
    public function __construct($attempt = 1)
    {
        $this->attempt = max(1, (int) $attempt);
    }

    public function getTitle()
    {
        return sprintf('Search index full rebuild (attempt %s)', $this->attempt);
    }

    /**
     * Attempt in the signature so retry jobs are not deduplicated away.
     */
    public function getSignature()
    {
        return md5(static::class . ':attempt:' . $this->attempt);
    }

    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }

    public function setup()
    {
        parent::setup();
        $this->totalSteps = 2;
    }

    public function process()
    {
        // reindexAllPosts creates the index when missing; create it up front
        // so the wipe below never hits a non-existent index
        OpenSearchService::createIndexIfNotExists();
        // Step 1: wipe the index so docs for deleted posts cannot linger
        $deleted = OpenSearchService::deleteAllDocuments();
        $this->currentStep = 1;
        $this->addMessage(sprintf('Deleted %s documents', $deleted));

        // Step 2: re-index everything that should be searchable
        $result = OpenSearchService::reindexAllPosts();
        $this->currentStep = 2;
        $this->addMessage(sprintf(
            'Indexed %s documents (%s errors)',
            $result['indexed'],
            count($result['errors'])
        ));

        $this->isComplete = true;
    }

    /**
     * Chain the consistency check once the rebuild finishes, and schedule
     * tomorrow night's run — queuedjobs are one-shot, so without this the
     * 3am schedule would die after the first seeded run.
     */
    public function afterComplete()
    {
        QueuedJobService::singleton()->queueJob(new SearchIndexConsistencyJob((int) $this->attempt));
        self::queueNextNightlyRun();
    }

    /**
     * Queues the next nightly run at 3am. "Tomorrow" is relative to the
     * current clock, so a run queued exactly at/after 3am today still
     * lands a full day out, never "now".
     */
    public static function queueNextNightlyRun(): void
    {
        $next = strtotime('tomorrow ' . self::RUN_HOUR . ':00:00');

        $startAfter = DBDatetime::create()->setValue($next)->Rfc2822();
        QueuedJobService::singleton()->queueJob(new self(), $startAfter);
    }
}
