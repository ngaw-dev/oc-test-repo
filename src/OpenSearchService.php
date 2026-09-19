<?php

// modules/silverstripe-opensearch/src/OpenSearchService.php

namespace AmolSW\OpenSearch;

use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;
use SilverStripe\Blog\Model\BlogPost;
use SilverStripe\ORM\DataList;
use SilverStripe\Versioned\Versioned;

/**
 * Facade over the OpenSearch search domain. Callers (controllers, tasks,
 * jobs) use only this class; the collaborators stay internal:
 * - OpenSearchClientFactory (client + index config)
 * - OpenSearchIndexRepository (raw index operations)
 * - OpenSearchDocumentBuilder (BlogPost <-> document mapping)
 */
class OpenSearchService
{
    public static function getIndexName(): string
    {
        return OpenSearchClientFactory::getIndexName();
    }

    public static function getClient(): Client
    {
        return OpenSearchClientFactory::createClient();
    }

    /**
     * Creates the search index (with mapping) when missing. Returns true when created.
     */
    public static function createIndexIfNotExists(): bool
    {
        return OpenSearchIndexRepository::createIndexIfNotExists(self::getClient());
    }

    /**
     * Deletes the whole index (documents + mapping). No-op when absent.
     */
    public static function deleteIndex(): void
    {
        OpenSearchIndexRepository::deleteIndex(self::getClient());
    }

    /**
     * Searches indexed documents. Empty term matches all.
     * Returns ['total' => int, 'documents' => [['ID' => string, 'Title' => ..., ...]]]
     */
    public static function searchDocuments(string $term = '', int $limit = 100, int $offset = 0): array
    {
        return OpenSearchIndexRepository::searchDocuments(self::getClient(), $term, $limit, $offset);
    }

    /**
     * Returns every PageId currently present in the OpenSearch index (paginated scan).
     */
    public static function getAllIndexedPageIds(): array
    {
        return OpenSearchIndexRepository::getAllIndexedPageIds(self::getClient());
    }

    /**
     * Deletes every document in the index (used by the nightly full rebuild).
     */
    public static function deleteAllDocuments(): int
    {
        return OpenSearchIndexRepository::deleteAllDocuments(self::getClient());
    }

    /**
     * Re-indexes all published BlogPosts under published top-level Blogs. Returns bulkIndex() result.
     */
    public static function reindexAllPosts(): array
    {
        $client = self::getClient();
        OpenSearchIndexRepository::createIndexIfNotExists($client);

        $documents = [];
        foreach (self::getPublishablePosts() as $post) {
            $documents[] = OpenSearchDocumentBuilder::buildDocument($post);
        }

        return OpenSearchIndexRepository::bulkIndex($client, $documents);
    }

    /**
     * Indexes (upserts) a single published BlogPost. No-op when the post is
     * not publicly visible (not on Live stage or its parent Blog is unpublished).
     */
    public static function indexPost(BlogPost $post): bool
    {
        // The publishable predicate (Live post under a Live root Blog) is the
        // single source of truth; callers (SearchIndexUpdateJob) normally
        // re-check themselves, but this method is also called directly
        // (tests, ad-hoc code) so the guard stays here. A post under an
        // unpublished Blog must not (re)enter the index.
        if (!OpenSearchDocumentBuilder::isPostPubliclyVisible($post->ID)) {
            return false;
        }

        $client = self::getClient();
        OpenSearchIndexRepository::createIndexIfNotExists($client);
        $result = OpenSearchIndexRepository::bulkIndex($client, [OpenSearchDocumentBuilder::buildDocument($post)]);

        // bulkIndex() reports per-item rejections in 'errors' without
        // throwing; treating those as success would mark the queued update
        // complete and never retry, leaving stale/missing search content
        return empty($result['errors']);
    }

    /**
     * Deletes a single BlogPost document from the index. Returns true when a doc was actually deleted.
     */
    public static function deletePost(int $pageId): bool
    {
        try {
            return OpenSearchIndexRepository::deleteDocument(self::getClient(), (string) $pageId);
        } catch (Missing404Exception $e) {
            // Deleting an already-absent doc is fine — treat as success no-op
            return false;
        }
    }

    /**
     * Returns Live-stage BlogPosts that belong to a published top-level Blog.
     */
    public static function getPublishablePosts(): DataList
    {
        return OpenSearchDocumentBuilder::getPublishablePosts();
    }

    /**
     * True when the post may appear in public search: on Live stage under a
     * published top-level Blog (Silverstripe unpublish is non-recursive, so
     * a Live post under an unpublished Blog is not publicly reachable).
     */
    public static function isPostPubliclyVisible(int $pageId): bool
    {
        return OpenSearchDocumentBuilder::isPostPubliclyVisible($pageId);
    }
}
