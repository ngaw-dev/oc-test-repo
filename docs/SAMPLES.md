# Sample Code

Practical examples for using `amolsw/silverstripe-opensearch` in a Silverstripe
project. All examples assume the module is installed and env vars are set
(see [SETUP.md](SETUP.md)).

## 1. Search from a controller

`OpenSearchService` is the only entry point. `searchDocuments()` returns a
normalized array — never a raw client response.

```php
<?php

// app/src/Controllers/MySearchController.php

namespace App\Controllers;

use AmolSW\OpenSearch\OpenSearchService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

class MySearchController extends Controller
{
    private static array $allowed_actions = ['query'];

    private static array $url_handlers = [
        'query' => 'query',
    ];

    /**
     * GET /my-search/query?q=<term> — JSON search results.
     */
    public function query(HTTPRequest $request): HTTPResponse
    {
        $term = trim((string) $request->getVar('q'));

        if ($term === '') {
            return $this->jsonResponse(['success' => true, 'total' => 0, 'results' => []]);
        }

        // Normalized result: ['total' => int, 'documents' => [...]]
        $result = OpenSearchService::create()->searchDocuments($term, 10);

        $results = array_map(
            fn (array $doc) => [
                'Title' => $doc['Title'] ?? '',
                'Summary' => $doc['Summary'] ?? '',
                'PageLink' => $doc['PageLink'] ?? '',
            ],
            $result['documents']
        );

        return $this->jsonResponse(['success' => true, 'total' => $result['total'], 'results' => $results]);
    }

    private function jsonResponse(array $body): HTTPResponse
    {
        $response = HTTPResponse::create(json_encode($body));
        $response->addHeader('Content-Type', 'application/json');

        return $response;
    }
}
```

## 2. Filter a DataList by relevance (search + DB fallback)

The module searches OpenSearch, your project keeps ordering authority. This is
the pattern used by this module's host blog — relevance order via ID FIELD()
sort, with a DB fallback when the cluster is unreachable.

```php
<?php

use AmolSW\OpenSearch\OpenSearchService;
use SilverStripe\Blog\Model\BlogPost;

$term = 'docker';

try {
    $result = OpenSearchService::create()->searchDocuments($term, 100);
    $relevanceIds = array_column($result['documents'], 'PageId');
} catch (RuntimeException $e) {
    // Cluster down: fall back to a DB LIKE search so the page still works
    $relevanceIds = null;
}

if ($relevanceIds !== null) {
    $posts = BlogPost::get()->filter('ID', $relevanceIds);
    // Apply relevance order: SQL FIELD() puts best matches first
    $idList = implode(',', array_map('intval', $relevanceIds));
    $posts = $posts->alterDataQuery(fn ($dq) => $dq->sort("FIELD(BlogPost.ID, $idList)"));
} else {
    $posts = BlogPost::get()->filterAny([
        'Title:PartialMatch' => $term,
        'Summary:PartialMatch' => $term,
    ]);
}
```

## 3. Index a single post manually

Publishing/unpublishing already queues sync jobs automatically (the module's
`BlogPostIndexExtension`). For ad-hoc indexing (imports, migrations):

```php
<?php

use AmolSW\OpenSearch\OpenSearchDocumentBuilder;
use AmolSW\OpenSearch\OpenSearchService;
use SilverStripe\Blog\Model\BlogPost;

$service = OpenSearchService::create();

// Only Live posts under a published Blog are indexable — indexPost() guards
// this itself and throws if the post is not publicly visible
$post = BlogPost::get()->byID(42);
if ($post && OpenSearchDocumentBuilder::isPostPubliclyVisible($post->ID)) {
    $service->indexPost($post);
}

// Remove one document (tolerates a missing doc — 404 is a no-op)
$service->deletePost(42);
```

## 4. Full rebuild

```php
<?php

use AmolSW\OpenSearch\OpenSearchService;

$service = OpenSearchService::create();

// Wipe + reindex every publishable post; returns the number of indexed posts
$count = $service->reindexAllPosts();
```

Or from the CLI (what you normally use):

```sh
ddev exec ./vendor/bin/sake tasks:IndexSearchDocumentsTask
```

## 5. Listing indexed documents (admin-style)

```php
<?php

use AmolSW\OpenSearch\OpenSearchService;

$service = OpenSearchService::create();

// All indexed PageIds — refreshes the index first so recent bulk writes
// are visible
$ids = $service->getAllIndexedPageIds();

// Every document in the index (match_all)
$all = $service->searchDocuments('', 500);

// Compare against what SHOULD be indexed to detect drift
$shouldIds = $service->getPublishablePosts()->column('ID');
$missing = array_diff($shouldIds, $ids);
$stale = array_diff($ids, $shouldIds);
```

## 6. Index management

```php
<?php

use AmolSW\OpenSearch\OpenSearchService;

$service = OpenSearchService::create();

// Create the index with the project-level mapping if missing (true = created)
$created = $service->createIndexIfNotExists();

// Drop the whole index (documents + mapping)
$service->deleteIndex();

// Remove all documents but keep the index/mapping
$service->deleteAllDocuments();

// Where is this pointing?
$name = $service->getIndexName(); // e.g. "search-index"
```

## 7. The project-level mapping YAML

Reminded from SETUP.md section 5 — this drives both the document shape
(`buildDocument()`) and the index mapping (`getIndexMapping()`):

```yaml
---
Name: opensearch-mapping
---
AmolSW\OpenSearch\OpenSearchClientFactory:
  mapping:
    Title:
      type: text
    Summary:
      type: text
    Content:
      type: plain_text   # HTML stripped before indexing; indexed as text
    PageId:
      type: integer
    ParentPageId:
      type: integer
    PageLink:
      type: keyword
```

Adding a new field: add it to the YAML, extend
`OpenSearchDocumentBuilder::getFieldValue()` with an extractor (unknown fields
throw `RuntimeException` at index time), then recreate the index in dev mode:

```sh
ddev exec ./vendor/bin/sake tasks:CreateSearchIndexTask --flush
ddev exec ./vendor/bin/sake tasks:ReindexSearchTask
```
