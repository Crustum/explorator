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
        'jobs' => [
            // Default push options applied to every queued Explorator job.
            // Only delay/expires (seconds) and priority are honored.
            'options' => [
                'delay' => null,
                'expires' => null,
                'priority' => null,
            ],
        ],
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
            // 'model-settings' => [
            //     \App\Model\Table\PostsTable::class => [
            //         'embedding' => [
            //             'embedder' => 'default',
            //             'dimensions' => 1536,
            //             'provider' => 'openai',
            //             'model' => 'text-embedding-3-small',
            //         ],
            //     ],
            // ],
            'model-settings' => [],
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
            // Per-table semantic / hybrid search settings. `search-parameters`
            // are merged into every query; `embedding` enables vector queries.
            // 'model-settings' => [
            //     \App\Model\Table\PostsTable::class => [
            //         'search-parameters' => [
            //             'query_by' => 'name',
            //         ],
            //         'embedding' => [
            //             'attribute' => 'embedding',
            //             'dimensions' => 1536,
            //         ],
            //     ],
            // ],
            'import_action' => env('TYPESENSE_IMPORT_ACTION', 'upsert'),
        ],
        'turbopuffer' => [
            'api_key' => env('TURBOPUFFER_API_KEY'),
            'region' => env('TURBOPUFFER_REGION', 'gcp-us-central1'),
            'base_url' => env('TURBOPUFFER_BASE_URL'),
            'timeout' => env('TURBOPUFFER_TIMEOUT', 60),
            'connect_timeout' => env('TURBOPUFFER_CONNECT_TIMEOUT', 5),
            'retries' => env('TURBOPUFFER_RETRIES', 3),
            // 'model-settings' => [
            //     \App\Model\Table\PostsTable::class => [
            //         'searchable-attributes' => [
            //             'name' => 2,
            //             'email' => 1,
            //         ],
            //         'embedding' => [
            //             'attribute' => 'embedding',
            //             'dimensions' => 1536,
            //             'provider' => 'openai',
            //             'model' => 'text-embedding-3-small',
            //         ],
            //         'schema' => [
            //             'name' => ['type' => 'string', 'full_text_search' => true],
            //             'email' => ['type' => 'string', 'full_text_search' => true],
            //             'embedding' => ['type' => '[1536]f32', 'ann' => true],
            //         ],
            //     ],
            // ],
            'model-settings' => [],
        ],
        'database' => [
            // Per-table embedding configuration used by semantic / hybrid search.
            // 'model-settings' => [
            //     \App\Model\Table\PostsTable::class => [
            //         'embedding' => [
            //             'attribute' => 'embedding',
            //             'dimensions' => 1536,
            //             'provider' => 'openai',
            //             'model' => 'text-embedding-3-small',
            //         ],
            //     ],
            // ],
            'model-settings' => [],
        ],
    ],
];
