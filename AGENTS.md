# modules/silverstripe-opensearch/

## Purpose

OpenSearch search domain — the service for indexing and querying the OpenSearch cluster. Setup guide: `docs/SETUP.md`; sample code: `docs/SAMPLES.md`; production cluster reference templates (redacted docker-compose/dashboards/nginx): `docs/examples/`.

## Ownership

- `OpenSearchService.php` (`AmolSW\OpenSearch`) — the only entry point for callers (controllers, tasks, jobs): `getClient()`, `getIndexName()`, `createIndexIfNotExists()`, `deleteIndex()`, `searchDocuments()` (normalized results), `getAllIndexedPageIds()`, `deleteAllDocuments()`, `reindexAllPosts()`, `indexPost()` (Live-stage guard, single upsert), `deletePost()` (404-tolerant), `getPublishablePosts()`. No listing/frontend logic lives here — filtering, DB fallback, and relevance ordering belong to `App\Controllers\AppSearchController` (app module) and the page controllers. Local DDEV cluster comes from the `ddev-opensearch` add-on (service `opensearch`, HTTP, security plugin disabled); rebuild via `sake tasks:ReindexSearchTask`
- `OpenSearchClientFactory.php` (`AmolSW\OpenSearch`) — `createClient()` from `OPENSEARCH_*` env vars (TLS verification on by default; `OPENSEARCH_VERIFY_SSL=false` opts out for self-signed certs), `getIndexName()` (lowercased `OPENSEARCH_INDEX_NAME`); mapping config is keyed to this class but defined at the PROJECT level (`app/_config/opensearch.yml`) — the module ships no mapping
- `Tasks/CreateSearchIndexTask.php` (`AmolSW\OpenSearch\Tasks`) — `sake tasks:CreateSearchIndexTask` creates the index with the explicit mapping from the project-level mapping YAML (via facade); in dev mode deletes + recreates so mapping changes apply (mapping only takes effect at creation time)
- `Tasks/IndexSearchDocumentsTask.php` (`AmolSW\OpenSearch\Tasks`) — `sake tasks:IndexSearchDocumentsTask` bulk-indexes all published BlogPosts (delegates to `OpenSearchService::reindexAllPosts()`)
- `Tasks/CreateSearchReindexJobTask.php` (`AmolSW\OpenSearch\Tasks`) — `sake tasks:CreateSearchReindexJobTask` seeds the recurring nightly `SearchReindexJob` (3am); safe to re-run, queuedjobs deduplicates by signature
- `Jobs/SearchIndexUpdateJob.php` (`AmolSW\OpenSearch\Jobs`) — per-post sync after publish/unpublish/archive: re-reads Live stage at run time, upserts via `indexPost()` or deletes via `deletePost()`
- `Jobs/SearchReindexJob.php` (`AmolSW\OpenSearch\Jobs`) — nightly 3am full rebuild (wipe + reindex); `afterComplete()` chains `SearchIndexConsistencyJob` and re-queues the next nightly run (queuedjobs are one-shot — the job self-perpetuates the schedule); `queueNextNightlyRun()` is only the seed entry point
- `Jobs/SearchIndexConsistencyJob.php` (`AmolSW\OpenSearch\Jobs`) — verifies indexed PageIds == publishable post IDs; on mismatch re-queues `SearchReindexJob` with attempt+1 (cap 3, then throws so queuedjobs marks it Broken)
- `Extensions/BlogPostIndexExtension.php` (`AmolSW\OpenSearch\Extensions`) — hooks `onAfterPublish`/`onAfterUnpublish` on BlogPost (registered in `_config/opensearch.yml`) to queue `SearchIndexUpdateJob`; archive is an unpublish under the hood
- `OpenSearchIndexRepository.php` (`AmolSW\OpenSearch`) — raw index operations, no CMS/domain knowledge; every method takes a `Client` param (factory is the single client source): `createIndexIfNotExists()`, `deleteIndex()`, `bulkIndex()` (batched, per-item errors), `searchDocuments()` (normalized `total` + `documents`; multi_match Title^2/Summary/Content + fuzziness, `_score` desc then PageId asc sort for term queries, PageId asc for match_all), `deleteDocument()` (throws on unexpected response; 404 propagates to caller), `deleteAllDocuments()`, `getAllIndexedPageIds()` (refresh + paginated scan)
- `OpenSearchDocumentBuilder.php` (`AmolSW\OpenSearch`) — BlogPost <-> document mapping, config-driven from the project-level mapping YAML: `buildDocument()` builds one entry per configured field (`Title`, `Summary`, `Content` via `getElementsForSearch()`, `PageId`, `ParentPageId`, `PageLink`; unknown fields throw), special mapping type `plain_text` extracts HTML-stripped plain text and `getIndexMapping()` translates it to OpenSearch `text`; throws when no mapping is configured; also `getPublishablePosts()` (Live BlogPosts under published top-level Blogs)

## Local Contracts

- Callers use only `OpenSearchService`; Repository/DocumentBuilder/Factory are internal collaborators of the facade
- Classes use the `AmolSW\OpenSearch` namespace
- Doc `_id` always equals BlogPost ID (PageId)
- Only the Live stage is indexed; draft-only and archived posts must never appear in the index
- Nightly chain: 3am `SearchReindexJob` → `SearchIndexConsistencyJob` → retry (max 3) → Broken
- Queue processing requires cron: `* * * * * cd <project root> && ./vendor/bin/sake tasks:ProcessJobQueueTask`

## Work Guidance

- Tests live in `modules/silverstripe-opensearch/tests/` against a dedicated test index (`SearchIndexTestTrait` sets `blog-test-index` env override, creates the index up front, restores the env var and deletes the index after the run, and refuses to run against non-local clusters), never the dev index; the test namespace is registered by the module's own `tests/bootstrap.php` — no project autoload-dev entry needed
- `getAllIndexedPageIds()` refreshes the index first — OpenSearch bulk docs are not immediately visible otherwise
- `testConsistencyThrowsAtAttemptCap` intentionally throws; consistency retries must never exceed `SearchReindexJob::MAX_ATTEMPTS`
- `createClient()` memoizes the Client per process — do not build clients ad hoc per call; unshared clients multiplied TLS handshakes and contributed to slow search pages (see Known Issues)

## Known Issues

- **2026-09 Slow search pages (fixed)**: `/blogs?q=` took 6–50s after moving to the remote live cluster. Root cause: `BlogsDirectoryController::getAllBlogPosts()` was invoked once per sidebar count (blogs/categories/tags), and each run re-executed the remote OpenSearch relevance query (N+1 remote round trips) plus rebuilt the client (fresh TLS handshake). Fixes: `AppSearchController::searchRelevanceIds()` memoizes per query (failures cached as null too), `BlogsDirectoryController` memoizes `getAllBlogPosts()` per request, `OpenSearchClientFactory::createClient()` memoizes the Client. Rule: any new listing helper must reuse `getAllBlogPosts()`/`searchRelevanceIds()` caches, never issue its own OpenSearch query per item.

## Verification

- `ddev exec env SS_PHPUNIT_FLUSH=1 php vendor/bin/phpunit -c modules/silverstripe-opensearch/phpunit.xml`
- `ddev php vendor/bin/phpcs modules/silverstripe-opensearch/src app/`
- Queue state visible in CMS at `/admin/queuedjobs`

## Child DOX Index

| Path | Scope |
|------|-------|
| `Admin/` | CMS "Search Data" admin section (`AmolSW\OpenSearch\Admin`) — no child doc, two-file boundary: `SearchDataManagerAdmin` (LeftAndMain at `/admin/search-data`: view/filter/delete/reindex OpenSearch documents; write actions validate a session SecurityID carried in the action links; raw GET `?term=` filter because the ArrayData list has no SearchContext; error paths catch `RuntimeException \| TransportException`) and `SearchDataGridToolbar` (`GridField_HTMLProvider` filling the `buttons-before-left/right` fragments defined by `GridFieldButtonRow('before')`) |
