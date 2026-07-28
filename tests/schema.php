<?php
declare(strict_types=1);

/**
 * Test database schema for Explorator plugin tests.
 *
 * Mirrors workbench migrations: searchable users (+ age), chirps, bookmarks.
 * MySQL FULLTEXT on `name` supports SearchUsingFullText in DatabaseEngineTest.
 */
return [
    'searchable_users' => [
        'columns' => [
            'id' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'name' => ['type' => 'string', 'length' => 255, 'null' => false],
            'email' => ['type' => 'string', 'length' => 255, 'null' => false],
            'age' => ['type' => 'integer', 'null' => true, 'default' => null],
            'created' => ['type' => 'datetime', 'null' => true, 'default' => null],
            'modified' => ['type' => 'datetime', 'null' => true, 'default' => null],
            'deleted' => ['type' => 'datetime', 'null' => true, 'default' => null],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
        'indexes' => [
            'searchable_users_name_fulltext' => [
                'type' => 'fulltext',
                'columns' => ['name'],
            ],
        ],
    ],
    'chirps' => [
        'columns' => [
            'id' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'content' => ['type' => 'text', 'null' => true],
            'explorator_id' => ['type' => 'string', 'length' => 255, 'null' => true],
            'deleted' => ['type' => 'datetime', 'null' => true, 'default' => null],
            'created' => ['type' => 'datetime', 'null' => true, 'default' => null],
            'modified' => ['type' => 'datetime', 'null' => true, 'default' => null],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
    ],
    'bookmarks' => [
        'columns' => [
            'id' => ['type' => 'integer', 'autoIncrement' => true, 'null' => false],
            'chirp_id' => ['type' => 'integer', 'null' => false],
            'label' => ['type' => 'string', 'length' => 255, 'null' => false],
            'created' => ['type' => 'datetime', 'null' => true, 'default' => null],
            'modified' => ['type' => 'datetime', 'null' => true, 'default' => null],
        ],
        'constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
    ],
];
