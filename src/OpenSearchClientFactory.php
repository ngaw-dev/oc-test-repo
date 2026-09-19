<?php

// modules/silverstripe-opensearch/src/OpenSearchClientFactory.php

namespace AmolSW\OpenSearch;

use OpenSearch\Client;
use OpenSearch\ClientBuilder;
use RuntimeException;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;

/**
 * Builds the OpenSearch client and resolves index configuration from
 * OPENSEARCH_* env vars and search.yml.
 */
class OpenSearchClientFactory
{
    /**
     * Lowercase index name from OPENSEARCH_INDEX_NAME env var.
     * OpenSearch requires lowercase index names.
     */
    public static function getIndexName(): string
    {
        // Environment::getEnv() reads both real env vars and Silverstripe's .env loader
        $indexName = Environment::getEnv('OPENSEARCH_INDEX_NAME');

        if (!$indexName) {
            throw new RuntimeException('OPENSEARCH_INDEX_NAME env var must be set.');
        }

        return strtolower($indexName);
    }

    /**
     * Mapping definition from config
     */
    public static function getMapping(): array
    {
        return Config::inst()->get(self::class, 'mapping') ?: [];
    }

    /**
     * Memoized client: building a client per call (per sidebar count, per
     * search) multiplied TLS handshakes to the remote cluster and slowed
     * page renders; one shared client per request process is enough.
     */
    private static ?Client $clientCache = null;

    /**
     * Builds a configured OpenSearch client from OPENSEARCH_* env vars
     */
    public static function createClient(): Client
    {
        if (self::$clientCache !== null) {
            return self::$clientCache;
        }

        $url = Environment::getEnv('OPENSEARCH_URL');
        $user = Environment::getEnv('OPENSEARCH_ADMIN_USER');
        $password = Environment::getEnv('OPENSEARCH_ADMIN_PASSWORD');

        if (!$url || !$user || !$password) {
            throw new RuntimeException(
                'OPENSEARCH_URL, OPENSEARCH_ADMIN_USER and OPENSEARCH_ADMIN_PASSWORD env vars must be set.'
            );
        }

        // Force IPv4: the remote host publishes AAAA records but the DDEV container has no working
        // IPv6 egress, and PHP curl (unlike curl CLI's happy-eyeballs) hangs on the IPv6 attempt
        $builder = ClientBuilder::create()
            ->setHosts([$url])
            ->setBasicAuthentication($user, $password)
            ->setConnectionParams([
                'client' => ['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]],
            ]);

        // TLS verification is on by default: admin credentials travel on every
        // request, so an unverified TLS connection to the remote cluster would
        // allow man-in-the-middle interception. Opt out only for clusters with
        // self-signed certificates (OPENSEARCH_VERIFY_SSL=false).
        $verifySsl = Environment::getEnv('OPENSEARCH_VERIFY_SSL');
        if ($verifySsl === 'false' || $verifySsl === '0' || $verifySsl === false) {
            $builder->setSSLVerification(false);
        }

        return self::$clientCache = $builder->build();
    }
}
