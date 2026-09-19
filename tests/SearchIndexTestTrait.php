<?php

// modules/silverstripe-opensearch/tests/SearchIndexTestTrait.php

namespace AmolSW\OpenSearch\Tests;

use AmolSW\OpenSearch\OpenSearchService;
use RuntimeException;
use SilverStripe\Core\Environment;

/**
 * Redirects the Search domain at a scratch OpenSearch index for the whole
 * test-class run and wipes it afterwards, so the dev index is never touched.
 */
trait SearchIndexTestTrait
{
    private const TEST_INDEX_NAME = 'blog-test-index';

    private static string|false $previousIndexName = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::guardLocalCluster();

        // Remember the previous value so the override cannot leak into
        // later test classes sharing this PHP process
        self::$previousIndexName = Environment::getEnv('OPENSEARCH_INDEX_NAME');
        Environment::setEnv('OPENSEARCH_INDEX_NAME', self::TEST_INDEX_NAME);

        // Create the index up front: without this it would be created
        // lazily by whichever test indexes first, making every read-only
        // test (draft exclusion, consistency checks) depend on class
        // declaration order and fail when run in isolation
        OpenSearchService::createIndexIfNotExists();
    }

    public static function tearDownAfterClass(): void
    {
        OpenSearchService::deleteIndex();

        if (self::$previousIndexName !== false) {
            Environment::setEnv('OPENSEARCH_INDEX_NAME', self::$previousIndexName);
            self::$previousIndexName = false;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Tests create and delete indices on the configured cluster — refuse
     * to run against anything but a local cluster so a flipped .env can
     * never point the suite at shared/production infrastructure.
     */
    private static function guardLocalCluster(): void
    {
        $url = Environment::getEnv('OPENSEARCH_URL');

        if (!$url) {
            throw new RuntimeException('OPENSEARCH_URL must be set for search tests.');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $allowed = ['localhost', '127.0.0.1', '::1', 'opensearch'];

        if (!in_array(strtolower($host), $allowed, true) && !str_ends_with(strtolower($host), '.ddev.site')) {
            throw new RuntimeException(sprintf(
                'Search tests refuse to run against remote cluster "%s" — point OPENSEARCH_URL at the local DDEV'
                . ' opensearch service (http://opensearch:9200) first.',
                $host
            ));
        }
    }
}
