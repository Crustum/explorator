<?php
declare(strict_types=1);

/**
 * Explorator Plugin Configuration
 *
 * Host applications copy settings into `config/explorator.php` and load via
 * `Configure::load('explorator')`. Keys are read as `Configure::read('Explorator.*')`.
 * Environment variables use the `EXPLORATOR_*` prefix.
 */
return [
    'Explorator' => [
        'driver' => env('EXPLORATOR_DRIVER', 'collection'),
        'prefix' => env('EXPLORATOR_PREFIX', ''),
        'queue' => env('EXPLORATOR_QUEUE', false),
        'after_commit' => false,
        'chunk' => [
            'searchable' => 500,
            'unsearchable' => 500,
        ],
        'soft_delete' => false,
        'wait_for_tasks' => filter_var(env('EXPLORATOR_WAIT_FOR_TASKS', false), FILTER_VALIDATE_BOOLEAN),
        'identify' => env('EXPLORATOR_IDENTIFY', false),
        'algolia' => [
            'id' => env('ALGOLIA_APP_ID', ''),
            'secret' => env('ALGOLIA_SECRET', ''),
            'index-settings' => [],
        ],
        'meilisearch' => [
            'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
            'key' => env('MEILISEARCH_KEY'),
            'index-settings' => [],
        ],
        'typesense' => [
            'client-settings' => [
                'api_key' => env('TYPESENSE_API_KEY', 'xyz'),
                'nodes' => [
                    [
                        'host' => env('TYPESENSE_HOST', 'localhost'),
                        'port' => env('TYPESENSE_PORT', '8108'),
                        'path' => env('TYPESENSE_PATH', ''),
                        'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                    ],
                ],
                'nearest_node' => [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'path' => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
                'connection_timeout_seconds' => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
                'healthcheck_interval_seconds' => env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 30),
                'num_retries' => env('TYPESENSE_NUM_RETRIES', 3),
                'retry_interval_seconds' => env('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
            ],
            'model-settings' => [],
            'import_action' => env('TYPESENSE_IMPORT_ACTION', 'upsert'),
        ],
    ],
];
