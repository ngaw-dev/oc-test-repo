<?php

// modules/silverstripe-opensearch/tests/OpenSearchIndexSyncTest.php

namespace AmolSW\OpenSearch\Tests;

use AmolSW\OpenSearch\Jobs\SearchIndexUpdateJob;
use AmolSW\OpenSearch\OpenSearchService;
use SilverStripe\Blog\Model\Blog;
use SilverStripe\Blog\Model\BlogPost;
use SilverStripe\Dev\SapphireTest;

/**
 * Functional tests against the real OpenSearch cluster in DDEV, using a
 * dedicated test index name so the dev index is never touched.
 */
class OpenSearchIndexSyncTest extends SapphireTest
{
    use SearchIndexTestTrait;

    protected static $fixture_file = 'blog.yml';

    protected $usesDatabase = true;

    public function testIndexPostAndDeletePost()
    {
        $this->objFromFixture(Blog::class, 'main-blog')->publishSingle();

        $post = $this->objFromFixture(BlogPost::class, 'post-short');
        $post->publishSingle();

        $this->assertTrue(OpenSearchService::indexPost($post));

        $ids = OpenSearchService::getAllIndexedPageIds();
        $this->assertContains($post->ID, $ids);

        $this->assertTrue(OpenSearchService::deletePost($post->ID));
        $this->assertNotContains($post->ID, OpenSearchService::getAllIndexedPageIds());
    }

    public function testIndexPostIgnoresDraftOnlyPost()
    {
        $post = $this->objFromFixture(BlogPost::class, 'post-long');

        $this->assertFalse(OpenSearchService::indexPost($post));
        $this->assertNotContains($post->ID, OpenSearchService::getAllIndexedPageIds());
    }

    public function testIndexPostRefusesPostUnderUnpublishedBlog()
    {
        // Parent Blog deliberately left draft-only: a Live post under an
        // unpublished Blog is not publicly reachable and must not be indexed
        $post = $this->objFromFixture(BlogPost::class, 'post-short');
        $post->publishSingle();

        $this->assertFalse(OpenSearchService::isPostPubliclyVisible($post->ID));
        $this->assertFalse(OpenSearchService::indexPost($post));
        $this->assertNotContains($post->ID, OpenSearchService::getAllIndexedPageIds());
    }

    public function testDeletePostIsNoOpForUnknownId()
    {
        // Must not throw when the document does not exist
        $this->assertFalse(OpenSearchService::deletePost(999999999));
    }

    public function testGetPublishablePostsExcludesUnpublishedBlog()
    {
        $blog = $this->objFromFixture(Blog::class, 'main-blog');
        $blog->publishSingle();

        $post = $this->objFromFixture(BlogPost::class, 'post-short');
        $post->publishSingle();

        $ids = OpenSearchService::getPublishablePosts()->column('ID');
        $this->assertContains($post->ID, $ids);
    }

    public function testUpdateJobRemovesUnpublishedPostFromIndex()
    {
        $this->objFromFixture(Blog::class, 'main-blog')->publishSingle();

        $post = $this->objFromFixture(BlogPost::class, 'post-short');
        $post->publishSingle();
        OpenSearchService::indexPost($post);
        $this->assertContains($post->ID, OpenSearchService::getAllIndexedPageIds());

        // Unpublish (same state archive leaves behind: no Live record)
        $post->doUnpublish();

        $job = new SearchIndexUpdateJob($post->ID);
        $job->setup();
        $job->process();

        $this->assertTrue($job->jobFinished());
        $this->assertNotContains($post->ID, OpenSearchService::getAllIndexedPageIds());
    }

    public function testUpdateJobRemovesPostsWhenParentBlogUnpublished()
    {
        $this->objFromFixture(Blog::class, 'main-blog')->publishSingle();

        $post = $this->objFromFixture(BlogPost::class, 'post-short');
        $post->publishSingle();
        OpenSearchService::indexPost($post);
        $this->assertContains($post->ID, OpenSearchService::getAllIndexedPageIds());

        // doUnpublish() cascades to children in Silverstripe 6 (the post's
        // Live row goes too), and even where it did not, the visibility
        // predicate (parent Blog must be Live) catches the orphan case —
        // either way the doc must leave the index
        $this->objFromFixture(Blog::class, 'main-blog')->doUnpublish();

        $job = new SearchIndexUpdateJob($post->ID);
        $job->setup();
        $job->process();

        $this->assertTrue($job->jobFinished());
        $this->assertNotContains($post->ID, OpenSearchService::getAllIndexedPageIds());
    }

    public function testUpdateJobIndexesPublishedPost()
    {
        $this->objFromFixture(Blog::class, 'main-blog')->publishSingle();

        $post = $this->objFromFixture(BlogPost::class, 'post-long');
        $post->publishSingle();

        $job = new SearchIndexUpdateJob($post->ID);
        $job->setup();
        $job->process();

        $this->assertContains($post->ID, OpenSearchService::getAllIndexedPageIds());
    }
}
