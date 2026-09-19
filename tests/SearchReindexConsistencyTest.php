<?php

// modules/silverstripe-opensearch/tests/SearchReindexConsistencyTest.php

namespace AmolSW\OpenSearch\Tests;

use AmolSW\OpenSearch\Jobs\SearchIndexConsistencyJob;
use AmolSW\OpenSearch\Jobs\SearchReindexJob;
use AmolSW\OpenSearch\OpenSearchService;
use SilverStripe\Blog\Model\Blog;
use SilverStripe\Blog\Model\BlogPost;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * End-to-end reindex + consistency check against the DDEV OpenSearch
 * cluster, using the dedicated test index.
 */
class SearchReindexConsistencyTest extends SapphireTest
{
    use SearchIndexTestTrait;

    protected static $fixture_file = 'blog.yml';

    protected $usesDatabase = true;

    public function testReindexThenConsistent()
    {
        $blog = $this->objFromFixture(Blog::class, 'main-blog');
        $blog->publishSingle();

        $short = $this->objFromFixture(BlogPost::class, 'post-short');
        $short->publishSingle();
        $long = $this->objFromFixture(BlogPost::class, 'post-long');
        $long->publishSingle();

        $reindex = new SearchReindexJob();
        $reindex->setup();
        $reindex->process();
        $this->assertTrue($reindex->jobFinished());

        $ids = OpenSearchService::getAllIndexedPageIds();
        $this->assertEqualsCanonicalizing(
            OpenSearchService::getPublishablePosts()->column('ID'),
            $ids,
            'Indexed PageIds must exactly match publishable post IDs after a rebuild'
        );

        $consistency = new SearchIndexConsistencyJob();
        $consistency->setup();
        $consistency->process();

        $this->assertTrue($consistency->jobFinished());
        // Case-sensitive: 'consistent' would also match the 'Inconsistent:' failure message
        $this->assertStringContainsString('Consistent: ', implode(' ', $consistency->getJobData()->messages));
    }

    public function testConsistencyDetectsStaleDocument()
    {
        $blog = $this->objFromFixture(Blog::class, 'main-blog');
        $blog->publishSingle();

        $short = $this->objFromFixture(BlogPost::class, 'post-short');
        $short->publishSingle();

        $reindex = new SearchReindexJob();
        $reindex->setup();
        $reindex->process();

        // Force a stale state: drop the Live record without touching the index
        $short->doUnpublish();

        $consistency = new SearchIndexConsistencyJob(1);
        $consistency->setup();
        $consistency->process();

        $messages = implode(' ', $consistency->getJobData()->messages);
        $this->assertStringContainsString('Inconsistent: ', $messages);
        $this->assertStringContainsString('attempt 2', $messages);
        // A mismatched job must not report success
        $this->assertStringNotContainsString('Consistent: ', $messages);
    }

    public function testConsistencyThrowsAtAttemptCap()
    {
        // When the index is inconsistent and the next attempt would exceed
        // the cap, the job throws so queuedjobs marks it Broken
        $this->expectException(\RuntimeException::class);
        $overCap = new SearchIndexConsistencyJob(SearchReindexJob::MAX_ATTEMPTS);
        $overCap->setup();

        // Publish a post so a mismatch exists (doc not yet indexed)
        $blog = $this->objFromFixture(Blog::class, 'main-blog');
        $blog->publishSingle();
        $post = $this->objFromFixture(BlogPost::class, 'post-short');
        $post->publishSingle();
        OpenSearchService::deleteAllDocuments();

        $overCap->process();
    }

    public function testReindexRemovesDeletedPosts()
    {
        $blog = $this->objFromFixture(Blog::class, 'main-blog');
        $blog->publishSingle();

        $short = $this->objFromFixture(BlogPost::class, 'post-short');
        $short->publishSingle();

        $reindex = new SearchReindexJob();
        $reindex->setup();
        $reindex->process();
        $this->assertContains($short->ID, OpenSearchService::getAllIndexedPageIds());

        // Delete post entirely, then reindex — full rebuild must purge it
        $shortId = $short->ID;
        $short->deleteFromStage(Versioned::LIVE);
        $short->deleteFromStage(Versioned::DRAFT);

        $reindex2 = new SearchReindexJob();
        $reindex2->setup();
        $reindex2->process();

        $this->assertNotContains($shortId, OpenSearchService::getAllIndexedPageIds());
    }
}
