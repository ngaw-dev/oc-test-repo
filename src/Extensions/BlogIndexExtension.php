<?php

// modules/silverstripe-opensearch/src/Extensions/BlogIndexExtension.php

namespace AmolSW\OpenSearch\Extensions;

use AmolSW\OpenSearch\Jobs\SearchIndexUpdateJob;
use SilverStripe\Blog\Model\BlogPost;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queues SearchIndexUpdateJobs for every child BlogPost when a Blog is
 * published or unpublished. Silverstripe unpublish is non-recursive, so
 * without this hook the posts under an unpublished Blog keep their Live
 * stage records and no BlogPost hook fires — their search documents
 * would stay publicly searchable until the nightly rebuild.
 *
 * @property DataObject|Versioned $owner
 */
class BlogIndexExtension extends Extension
{
    public function onAfterPublish()
    {
        $this->queueChildPostSync();
    }

    public function onAfterUnpublish()
    {
        $this->queueChildPostSync();
    }

    private function queueChildPostSync(): void
    {
        $postIds = Versioned::get_by_stage(BlogPost::class, Versioned::DRAFT)
            ->filter('ParentID', $this->owner->ID)
            ->column('ID');

        if (empty($postIds)) {
            return;
        }

        // One job for the whole blog event; the job re-checks the public
        // visibility predicate per post at run time, so publishing the Blog
        // re-indexes the children and unpublishing removes them
        QueuedJobService::singleton()->queueJob(
            new SearchIndexUpdateJob($postIds)
        );
    }
}
