<?php

// modules/silverstripe-opensearch/src/Jobs/SearchIndexUpdateJob.php

namespace AmolSW\OpenSearch\Jobs;

use AmolSW\OpenSearch\OpenSearchService;
use RuntimeException;
use SilverStripe\Blog\Model\BlogPost;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Keeps BlogPosts in sync with the OpenSearch index after publish /
 * unpublish / archive of a post or of its parent Blog. Doc _id = PageId,
 * so deciding between upsert and delete only needs the page ID.
 */
class SearchIndexUpdateJob extends AbstractQueuedJob
{
    /**
     * QueuedJobs recreates jobs from descriptor data where the saved
     * constructor args come back as JSON strings, so no scalar type hints here.
     *
     * @param int|int[]|string|string[]|null $pageIds BlogPost ID(s) to sync
     */
    public function __construct($pageIds = null)
    {
        if ($pageIds !== null) {
            $this->pageIds = array_values(array_unique(array_map('intval', (array) $pageIds)));
        }
    }

    public function getTitle()
    {
        return sprintf('Search index update for page(s) %s', implode(', ', $this->pageIds ?? []));
    }

    /**
     * Include pageIds in the signature so queued jobs for different pages
     * are not deduplicated into one.
     */
    public function getSignature()
    {
        return md5(static::class . ':' . implode(',', $this->pageIds ?? []));
    }

    public function getJobType()
    {
        return QueuedJob::IMMEDIATE;
    }

    public function setup()
    {
        parent::setup();
        $this->totalSteps = count($this->pageIds ?? []);
    }

    public function process()
    {
        $pageIds = array_map('intval', $this->pageIds ?? []);

        foreach ($pageIds as $pageId) {
            // The public-visibility predicate decides upsert vs delete: an
            // unpublished/archived post has no Live record, and a post under
            // an unpublished (non-recursively unpublished) Blog is not
            // publicly reachable either — both must leave the index
            if (OpenSearchService::isPostPubliclyVisible($pageId)) {
                $livePost = Versioned::get_by_stage(BlogPost::class, Versioned::LIVE)->byID($pageId);

                $indexed = OpenSearchService::indexPost($livePost);

                if (!$indexed) {
                    // bulkIndex() rejections must not be treated as success:
                    // throw so queuedjobs marks the job for retry
                    throw new RuntimeException(sprintf('OpenSearch bulk index rejected page %s', $pageId));
                }

                $this->addMessage(sprintf('Indexed page %s (%s)', $pageId, $livePost->Title));
            } else {
                // deletePost is a safe no-op when the doc is already gone
                OpenSearchService::deletePost($pageId);

                // Draft-stage lookup: unpublished posts still have a draft
                // record with a title, archived ones return null here
                $title = BlogPost::get()->byID($pageId)?->Title;
                $this->addMessage(sprintf(
                    'Removed page %s (%s) from index',
                    $pageId,
                    $title ?: 'no title found'
                ));
            }

            $this->currentStep++;
        }

        $this->isComplete = true;
    }
}
