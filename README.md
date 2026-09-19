# amolsw/silverstripe-opensearch

OpenSearch integration for Silverstripe CMS 6. Indexes published BlogPosts into an
OpenSearch cluster, provides a search facade for controllers, queued jobs for sync
and nightly rebuilds, and a CMS admin for inspecting indexed documents.

## Requirements

- PHP 8.3+
- Silverstripe Framework 6.2+
- silverstripe/blog 5.1+
- symbiote/silverstripe-queuedjobs 6.2+
- An OpenSearch cluster (e.g. the `ddev-opensearch` DDEV add-on)

## Installation

Full step-by-step setup (DDEV cluster, env vars, first index build, cron) is in
[docs/SETUP.md](docs/SETUP.md). Practical code examples (search, relevance
ordering, indexing, index management) are in [docs/SAMPLES.md](docs/SAMPLES.md).

Quick start — the module is a standalone git repo; register it as a composer
path repository:

```json
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
composer require amolsw/silverstripe-opensearch:@dev
```

## Configuration

Environment variables:

| Variable | Purpose |
|----------|---------|
| `OPENSEARCH_URL` | Cluster URL, e.g. `http://opensearch:9200` (DDEV) or `https://host:9200` |
| `OPENSEARCH_ADMIN_USER` | Basic-auth username (required even for local clusters with security disabled) |
| `OPENSEARCH_ADMIN_PASSWORD` | Basic-auth password (required even for local clusters with security disabled) |
| `OPENSEARCH_INDEX_NAME` | Index name (lowercased) |
| `OPENSEARCH_VERIFY_SSL` | Set `false` to skip TLS verification for self-signed certs |

Index field mapping is **not** shipped by the module — each project defines it via
YAML on `AmolSW\OpenSearch\OpenSearchClientFactory` (e.g. `app/_config/opensearch.yml`).
The special `type: plain_text` extracts plain text (HTML stripped) instead of raw
HTML. See [docs/SETUP.md](docs/SETUP.md) section 5.

## Usage

- All callers go through the `AmolSW\OpenSearch\OpenSearchService` facade.
- `sake tasks:CreateSearchIndexTask` — create the index (dev mode recreates it).
- `sake tasks:IndexSearchDocumentsTask` — bulk-index all published posts.
- `sake tasks:CreateSearchReindexJobTask` — seed the nightly 3am reindex job.
- Publishing/unpublishing a BlogPost queues a sync job automatically.
- Queue processing requires cron: `* * * * * ./vendor/bin/sake tasks:ProcessJobQueueTask`.

## Local development with DDEV

The module is developed against the [`ddev-opensearch`](https://github.com/ddev/ddev-opensearch)
add-on, which provides a local single-node OpenSearch cluster and Dashboards UI:

```sh
ddev add-on get ddev/ddev-opensearch
ddev restart
```

What the add-on installs (`.ddev/` in your project):

- `.ddev/docker-compose.opensearch.yaml` — two services:
    - `opensearch` — single-node cluster (port 9200; HTTPS 9201), security plugin disabled,
      demo config disabled, 512m heap, `analysis-icu` + `analysis-phonetic` plugins baked in,
      named volume `opensearch` for data persistence, healthcheck on `localhost:9200`
    - `opensearch-dashboards` — Dashboards UI (port 5601; HTTPS 5602) pointed at the cluster
- `.ddev/addon-metadata/ddev-opensearch/manifest.yaml` — add-on registration for `ddev add-on remove`

The web container gets a `depends_on: opensearch` so the cluster starts with the site.
Connect from PHP at `http://opensearch:9200` (or `https://opensearch:9200`); from the host
at `https://<project>.ddev.site:9201`. Override the image tag via the `OPENSEARCH_TAG`
(and `OPENSEARCH_DASHBOARDS_TAG`) env vars.

## License

MIT — free to use, modify, and distribute. See [LICENSE](LICENSE).
