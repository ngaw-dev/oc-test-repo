<?php

// modules/silverstripe-opensearch/tests/bootstrap.php

// Locate the project root that owns vendor/: walk up from this module until
// a composer autoloader is found (works for path-repository installs and
// standalone checkouts inside a project)
$dir = __DIR__;
while ($dir !== '/' && !file_exists($dir . '/vendor/autoload.php')) {
    $dir = dirname($dir);
}
if (!file_exists($dir . '/vendor/autoload.php')) {
    fwrite(STDERR, 'Could not locate a project vendor/autoload.php above ' . __DIR__ . "\n");
    exit(1);
}

// Module dev autoloader: the Tests namespace is not part of the module's
// production autoload, so register it here for the test run only
$loader = require $dir . '/vendor/autoload.php';
$loader->addPsr4('AmolSW\\OpenSearch\\Tests\\', __DIR__ . '/');

// Framework bootstrap (fixtures, manifests) keys off BASE_PATH
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $dir);
}
