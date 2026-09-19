# Setup Guide

Complete instructions for setting up `amolsw/silverstripe-opensearch` in a
Silverstripe CMS 6 project running on DDEV. For practical code examples see
[SAMPLES.md](SAMPLES.md).

## 1. Prerequisites

- DDEV with Docker running
- PHP 8.3+
- A Silverstripe CMS 6.2+ project with:
    - `silverstripe/blog` ^5.1
    - `dnadesign/silverstripe-elemental` ^6.2
    - `symbiote/silverstripe-queuedjobs` ^6.2

## 2. Install the OpenSearch cluster (DDEV add-on)

```sh
ddev add-on get ddev/ddev-opensearch
ddev restart
```

This adds `.ddev/docker-compose.opensearch.yaml` (single-node `opensearch`
service on port 9200 with security plugin disabled, plus `opensearch-dashboards`
on 5601) and `.ddev/addon-metadata/ddev-opensearch/manifest.yaml`.

Verify the cluster from inside DDEV:

```sh
ddev exec curl -s http://opensearch:9200
```

## 3. Install the module (standalone git repository)

This module is its own git repository. Clone it anywhere (e.g. a sibling of your
project) and register it as a path repository:

```sh
git clone git@github-ngaw:ngaw-dev/oc-test-repo.git ../silverstripe-opensearch
```

```json
// composer.json (project root)
{
    "repositories": [
        {
            "type": "path",
            "url": "../silverstripe-opensearch",
            "options": { "symlink": true }
        }
    ]
}
```

```sh
ddev composer require amolsw/silverstripe-opensearch:@dev
```

Composer symlinks `vendor/amolsw/silverstripe-opensearch` to the checkout, so
module changes are picked up immediately — no reinstall needed. The `path`
repository can be removed once the module is published to Packagist, then a
plain `ddev composer require amolsw/silverstripe-opensearch` works.

## 4. Configure environment variables

Add to `.env`:

```ini
OPENSEARCH_URL=http://opensearch:9200
OPENSEARCH_ADMIN_USER=admin
OPENSEARCH_ADMIN_PASSWORD=password
OPENSEARCH_INDEX_NAME=search-index
# Set to false only for clusters with self-signed certificates
OPENSEARCH_VERIFY_SSL=true
```

Notes:

- Inside DDEV the host is `http://opensearch:9200` (service name); the security
  plugin is disabled locally, but user/password are still required env vars.
- `OPENSEARCH_INDEX_NAME` is lowercased by the factory; index mapping changes
  only apply at index creation time.

## 5. Add the field mapping YAML at the project level

The module ships **no** index mapping — each project defines which fields are
indexed, in its own config (e.g. `app/_config/opensearch.yml`):

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
      type: plain_text
    PageId:
      type: integer
    ParentPageId:
      type: integer
    PageLink:
      type: keyword
```

How it works:

- Each key becomes a document field sent to OpenSearch; the module builds one
  document entry per configured field at index time.
- `type` is the OpenSearch field type, **except** the special `plain_text`:
  it tells the document builder to strip HTML markup from the field value
  before indexing (elemental block HTML, editor HTML). The index itself
  receives a normal `text` field — OpenSearch never sees `plain_text`.
- Supported extractable fields: `Title`, `Summary`, `Content` (elemental
  blocks), `PageId`, `ParentPageId`, `PageLink`. An unknown field name throws
  a `RuntimeException` at index time.
- No mapping configured at all also throws — the module refuses to index
  without a project-level mapping.
- Mapping changes only apply at index creation time; recreate with
  `CreateSearchIndexTask` (dev mode deletes + recreates).

## 6. Create the index and run the first index build

```sh
ddev exec ./vendor/bin/sake dev/build flush=1
ddev exec ./vendor/bin/sake dev/tasks/CreateSearchIndexTask
ddev exec ./vendor/bin/sake dev/tasks/IndexSearchDocumentsTask
```

`CreateSearchIndexTask` deletes and recreates the index in dev mode, so mapping
changes in `_config/opensearch.yml` apply on the next run.

## 7. Set up automatic syncing

Queue a per-post sync on publish/unpublish (config-driven, active after flush)
and seed the nightly full rebuild job (3am):

```sh
ddev exec ./vendor/bin/sake dev/tasks/CreateSearchReindexJobTask
```

Queue processing requires cron (every minute):

```cron
* * * * * cd <project root> && ./vendor/bin/sake dev/tasks/ProcessJobQueueTask
```

Inspect queue state in the CMS at `/admin/queuedjobs`.

## 8. Using the search API

All callers go through the facade:

```php
use AmolSW\OpenSearch\OpenSearchService;

$service = OpenSearchService::create();
$results = $service->searchDocuments('my query');
// $results = ['total' => int, 'documents' => [...]]

foreach ($service->getPublishablePosts() as $post) {
    $service->indexPost($post);
}
```

CMS admin for indexed documents: `/admin/search-data`.

## 9. Run the tests

Tests run against the real DDEV cluster using a dedicated `blog-test-index`
index (never the dev index). The module ships its own phpunit config and
bootstrap (registers the `AmolSW\OpenSearch\Tests` namespace — no project
autoload-dev changes needed):

```sh
ddev exec env SS_PHPUNIT_FLUSH=1 php vendor/bin/phpunit -c modules/silverstripe-opensearch/phpunit.xml
```

## 10. Troubleshooting

| Symptom | Fix |
|---------|-----|
| `OPENSEARCH_URL ... must be set` | Env vars missing from `.env`; run `ddev restart` after edits |
| TLS/self-signed certificate errors | Set `OPENSEARCH_VERIFY_SSL=false` for local clusters |
| Mapping changes not applied | Delete and recreate: `CreateSearchIndexTask` in dev mode |
| `No OpenSearch mapping configured` | Add the mapping YAML to your project (section 5, e.g. `app/_config/opensearch.yml`) |
| `Unknown field ... in OpenSearch mapping config` | Field name in mapping YAML has no extractor; remove it or extend `OpenSearchDocumentBuilder::getFieldValue()` |
| Bulk docs not visible immediately | Expected — the repository refreshes the index before scans |
| Jobs stuck in queue | Cron for `ProcessJobQueueTask` not running; check `/admin/queuedjobs` |
