<?php

// modules/silverstripe-opensearch/src/Extensions/BlogPostIndexExtension.php

namespace AmolSW\OpenSearch\Extensions;

use AmolSW\OpenSearch\Jobs\SearchIndexUpdateJob;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queues a SearchIndexUpdateJob whenever a BlogPost is published,
 * unpublished or archived, so the OpenSearch index stays in sync
 * without touching the publish request itself.
 *
 * @property DataObject|Versioned $owner
 */
class BlogPostIndexExtension extends Extension
{
    /**
     * Blog module's archive action is an unpublish under the hood, so
     * onAfterUnpublish covers archive removal from the index too.
     */
    public function onAfterPublish()
    {
        $this->queueIndexUpdate();
    }

    public function onAfterUnpublish()
    {
        $this->queueIndexUpdate();
    }

    private function queueIndexUpdate(): void
    {
        // The job only needs the page ID; it re-reads Live stage at run
        // time to decide between index upsert and document deletion
        QueuedJobService::singleton()->queueJob(
            new SearchIndexUpdateJob((int) $this->owner->ID)
        );
    }
}
