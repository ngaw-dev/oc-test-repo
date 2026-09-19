# Architecture

How `amolsw/silverstripe-opensearch` is structured: who calls what, where the
boundaries are, and how documents stay in sync with the CMS.

- Interactive diagram: [examples/architecture.html](examples/architecture.html)
- Static overview image: [examples/silverstripe-opensearch-module.png](examples/silverstripe-opensearch-module.png)

## Layers

```text
Callers (controllers, CMS admin, tasks)
    └── OpenSearchService (facade — the only entry point)
            ├── OpenSearchClientFactory   client + index name from OPENSEARCH_* env
            ├── OpenSearchDocumentBuilder BlogPost <-> document mapping
            └── OpenSearchIndexRepository raw index operations
                    └── OpenSearch cluster (HTTP :9200)
```

- **Callers** — `App\Controllers\AppSearchController` (frontend listing/search),
  the CMS "Search Data" admin (`/admin/search-data`), and the `sake` tasks.
  Callers never touch the client, repository, or builder directly.
- **OpenSearchService** (`src/OpenSearchService.php`) — the facade. Owns the
  Live-stage guard, normalization, and the public API: `getClient()`,
  `getIndexName()`, `createIndexIfNotExists()`, `deleteIndex()`,
  `searchDocuments()`, `getAllIndexedPageIds()`, `deleteAllDocuments()`,
  `reindexAllPosts()`, `indexPost()`, `deletePost()`, `getPublishablePosts()`.
- **OpenSearchClientFactory** (`src/OpenSearchClientFactory.php`) — builds the
  OpenSearch `Client` from `OPENSEARCH_*` env vars (TLS verification on by
  default). The client is memoized per process — do not build clients ad hoc.
- **OpenSearchDocumentBuilder** (`src/OpenSearchDocumentBuilder.php`) — maps
  BlogPosts to index documents, driven by the project-level mapping YAML
  (`app/_config/opensearch.yml`; the module ships no mapping). Reads the Live
  stage only.
- **OpenSearchIndexRepository** (`src/OpenSearchIndexRepository.php`) — raw
  index operations with no CMS/domain knowledge: create/delete index, bulk
  index, search, delete document, paginated PageId scan.

## Regions

- **Module (`AmolSW\OpenSearch`)** — service facade, factory, builder,
  repository, jobs, tasks, and the BlogPost extension.
- **App + infrastructure** — frontend/controllers, the queuedjobs queue
  (cron `tasks:ProcessJobQueueTask`), the Silverstripe DB (Live stage), and the
  OpenSearch cluster.

## Write paths

1. **Publish sync (near-real-time)** — `onAfterPublish` / `onAfterUnpublish`
   (archive is an unpublish) on BlogPost via `BlogPostIndexExtension` queues a
   `SearchIndexUpdateJob`. At run time the job re-reads the Live stage and
   upserts via `indexPost()` or deletes via `deletePost()` (404-tolerant).
2. **Nightly rebuild (3am)** — `SearchReindexJob` wipes and reindexes
   everything, then `afterComplete()` chains `SearchIndexConsistencyJob` and
   re-queues the next nightly run. Seed once with
   `sake tasks:CreateSearchReindexJobTask`.
3. **Consistency check** — compares indexed PageIds against publishable post
   IDs; on mismatch it re-queues `SearchReindexJob` with attempt+1, capped at
   `SearchReindexJob::MAX_ATTEMPTS` (3), after which it throws so queuedjobs
   marks it Broken.

## Read path

Frontend search goes controller → `OpenSearchService::searchDocuments()` →
repository multi_match query (Title^2 / Summary / Content, fuzziness) →
normalized `total` + `documents`. Relevance ordering and DB fallback live in
the app module, not here. Memoization rules: any listing helper must reuse
`getAllBlogPosts()` / `searchRelevanceIds()` caches — never issue a per-item
OpenSearch query (see Known Issues in AGENTS.md).

## Invariants

- Doc `_id` always equals the BlogPost ID (PageId).
- Only the Live stage is indexed; draft-only and archived posts never appear.
- Mapping takes effect only at index creation — dev-mode
  `CreateSearchIndexTask` deletes and recreates the index.
- `getAllIndexedPageIds()` refreshes the index first (bulk docs are not
  immediately visible otherwise).
