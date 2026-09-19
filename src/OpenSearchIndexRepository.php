<?php

// modules/silverstripe-opensearch/src/OpenSearchIndexRepository.php

namespace AmolSW\OpenSearch;

use OpenSearch\Client;
use RuntimeException;

/**
 * Raw index operations against the OpenSearch cluster — no CMS/domain
 * knowledge. Wraps index lifecycle, bulk indexing, document queries
 * and deletions. Every method receives the Client; the factory is the
 * single client source.
 */
class OpenSearchIndexRepository
{
    private const PAGEID_SORT = ['PageId' => ['order' => 'asc', 'unmapped_type' => 'integer']];

    /**
     * Creates the index if it does not exist. Returns true when created, false when it already existed.
     */
    public static function createIndexIfNotExists(Client $client): bool
    {
        $indexName = OpenSearchClientFactory::getIndexName();

        if ($client->indices()->exists(['index' => $indexName])) {
            return false;
        }

        $client->indices()->create([
            'index' => $indexName,
            'body' => [
                // DocumentBuilder derives the index mapping from the project-level
                // mapping config, translating plain_text fields to OpenSearch text
                'mappings' => ['properties' => OpenSearchDocumentBuilder::getIndexMapping()],
            ],
        ]);

        return true;
    }

    /**
     * Deletes the whole index (documents + mapping). Mapping changes only
     * apply at creation time, so recreating is the only way to apply them.
     */
    public static function deleteIndex(Client $client): void
    {
        $indexName = OpenSearchClientFactory::getIndexName();

        if ($client->indices()->exists(['index' => $indexName])) {
            $client->indices()->delete(['index' => $indexName]);
        }
    }

    /**
     * Bulk indexes documents. Each document must contain a '_id' key used as the OpenSearch doc id.
     */
    public static function bulkIndex(Client $client, array $documents, int $batchSize = 100): array
    {
        $indexName = OpenSearchClientFactory::getIndexName();
        $indexed = 0;
        $errors = [];

        foreach (array_chunk($documents, $batchSize) as $batch) {
            $body = [];
            foreach ($batch as $document) {
                $body[] = ['index' => ['_index' => $indexName, '_id' => $document['_id']]];
                // '_id' is metadata for the bulk action line, not an indexed field
                unset($document['_id']);
                $body[] = $document;
            }

            $response = $client->bulk(['body' => $body]);

            foreach ($response['items'] ?? [] as $item) {
                if (isset($item['index']['error'])) {
                    $errors[] = $item['index'];
                } else {
                    $indexed++;
                }
            }
        }

        return ['indexed' => $indexed, 'errors' => $errors];
    }

    /**
     * Searches indexed documents. Empty term matches all.
     * Returns ['total' => int, 'documents' => [['ID' => string, 'Title' => ..., ...]]]
     */
    public static function searchDocuments(Client $client, string $term = '', int $limit = 100, int $offset = 0): array
    {
        $indexName = OpenSearchClientFactory::getIndexName();

        $body = [
            'from' => $offset,
            'size' => $limit,
            'query' => $term === ''
                ? ['match_all' => new \stdClass()]
                : [
                    'multi_match' => [
                        'query' => $term,
                        'fields' => ['Title^2', 'Summary', 'Content'],
                        'fuzziness' => 'AUTO',
                        'prefix_length' => 1
                    ],
                ],
            // Relevance (_score) order for term queries so consumers can
            // preserve OpenSearch ranking; deterministic
            // PageId order for match_all scans
            'sort' => $term === ''
                ? [self::PAGEID_SORT]
                : [
                    ['_score' => ['order' => 'desc']],
                    self::PAGEID_SORT,
                ],
        ];

        $response = $client->search(['index' => $indexName, 'body' => $body]);

        $documents = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $source = $hit['_source'] ?? [];
            $source['ID'] = (string) $hit['_id'];
            $documents[] = $source;
        }

        return [
            'total' => (int) ($response['hits']['total']['value'] ?? 0),
            'documents' => $documents,
        ];
    }

    /**
     * Deletes a single document by its OpenSearch doc id (= PageId).
     * Throws Missing404Exception when the doc is absent; callers decide
     * whether that is tolerable.
     */
    public static function deleteDocument(Client $client, string $id): bool
    {
        $response = $client->delete([
            'index' => OpenSearchClientFactory::getIndexName(),
            'id' => $id,
        ]);

        $result = $response['result'] ?? null;

        if ($result !== 'deleted') {
            // Silently swallowing unexpected responses would hide cluster issues
            throw new RuntimeException(sprintf(
                'Unexpected OpenSearch delete result for doc "%s": %s',
                $id,
                var_export($result, true)
            ));
        }

        return true;
    }

    /**
     * Deletes every document in the index via delete-by-query.
     */
    public static function deleteAllDocuments(Client $client): int
    {
        $response = $client->deleteByQuery([
            'index' => OpenSearchClientFactory::getIndexName(),
            'body' => ['query' => ['match_all' => new \stdClass()]],
        ]);

        return (int) ($response['deleted'] ?? 0);
    }

    /**
     * Returns every PageId currently present in the OpenSearch index (paginated scan).
     */
    public static function getAllIndexedPageIds(Client $client): array
    {
        $indexName = OpenSearchClientFactory::getIndexName();

        // Bulk-indexed docs are not immediately searchable; refresh so
        // consistency checks observe a settled index
        $client->indices()->refresh(['index' => $indexName]);

        $ids = [];
        $offset = 0;
        $pageSize = 1000;

        do {
            $response = $client->search([
                'index' => $indexName,
                'body' => [
                    'from' => $offset,
                    'size' => $pageSize,
                    '_source' => ['PageId'],
                    'query' => ['match_all' => new \stdClass()],
                ],
            ]);

            $hits = $response['hits']['hits'] ?? [];
            foreach ($hits as $hit) {
                $ids[] = (int) ($hit['_source']['PageId'] ?? $hit['_id']);
            }

            $offset += $pageSize;
            $total = (int) ($response['hits']['total']['value'] ?? 0);
        } while ($offset < $total);

        return $ids;
    }
}
