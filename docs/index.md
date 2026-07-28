# CakePHP Explorator Plugin

- [Introduction](#introduction)
- [Installation](#installation)
    - [Installing the Plugin](#installing-the-plugin)
    - [Making Tables Searchable](#making-tables-searchable)
    - [Queueing](#queueing)
    - [Waiting for Engine Tasks](#waiting-for-engine-tasks)
- [Driver Prerequisites](#driver-prerequisites)
    - [Algolia](#algolia)
    - [Meilisearch](#meilisearch)
    - [Typesense](#typesense)
- [Configuration](#configuration)
    - [Configuring Searchable Data](#configuring-searchable-data)
    - [Configuring Table Engines](#configuring-table-engines)
- [Database / Collection Engines](#database-and-collection-engines)
    - [Database Engine](#database-engine)
    - [Collection Engine](#collection-engine)
- [Third-Party Engine Configuration](#third-party-engine-configuration)
    - [Configuring Table Indexes](#configuring-table-indexes)
    - [Algolia](#algolia-configuration)
    - [Meilisearch](#meilisearch-configuration)
    - [Typesense](#typesense-configuration)
- [Third-Party Engine Indexing](#indexing)
    - [Batch Import](#batch-import)
    - [Adding Records](#adding-records)
    - [Updating Records](#updating-records)
    - [Removing Records](#removing-records)
    - [Pausing Indexing](#pausing-indexing)
    - [Conditionally Searchable Entities](#conditionally-searchable-entities)
- [Searching](#searching)
    - [Where Clauses](#where-clauses)
    - [Pagination](#pagination)
    - [Soft Deleting](#soft-deleting)
    - [Customizing Engine Searches](#customizing-engine-searches)
- [Custom Engines](#custom-engines)
    - [Writing the Engine](#writing-the-engine)
    - [Registering the Engine](#registering-the-engine)
- [Console Commands](#console-commands)
    - [Importing Records](#importing-records)
    - [Flushing Indexes](#flushing-indexes)
    - [Managing Indexes](#managing-indexes)
    - [Syncing Index Settings](#syncing-index-settings)
    - [Command Reference](#command-reference)
- [Testing](#testing)
    - [Asserting Index Writes](#asserting-index-writes)
    - [Asserting Removals and Searches](#asserting-removals-and-searches)
    - [Inspecting Indexed Content](#inspecting-indexed-content)
    - [Available Assertions](#available-assertions)

<a name="introduction"></a>
## Introduction

[CakePHP Explorator](https://github.com/Crustum/explorator) provides a simple, driver-based solution for adding full-text search to your CakePHP tables and entities. With the Searchable behavior attached, Explorator keeps search indexes in sync when entities are saved or deleted.

Explorator ships with a built-in `database` engine that uses MySQL / PostgreSQL full-text indexes and `LIKE` clauses to search your existing database — no external service required. For most applications, this is all you need.

Explorator also includes drivers for [Algolia](https://www.algolia.com/), [Meilisearch](https://www.meilisearch.com), and [Typesense](https://typesense.org) when you need features like typo tolerance, faceted filtering, or geo-search at massive scale. A `collection` driver is also available for local development and testing, and you are free to write [custom engines](#custom-engines) as well.

<a name="installation"></a>
## Installation

<a name="installing-the-plugin"></a>
### Installing the Plugin

Install via Composer:

```bash
composer require crustum/explorator
```

Load the plugin:

```bash
bin/cake plugin load Crustum/Explorator
```

> [!NOTE]
> Register the plugin in `config/plugins.php` (or `Application::bootstrap()`).

> [!TIP]
> **After the plugin is loaded**, install configuration with PluginManifest:

```bash
bin/cake manifest install --plugin Crustum/Explorator
```

That publishes `config/explorator.php` and appends loading of that file to your application's bootstrap. Settings are read as `Configure::read('Explorator.*')`. Environment variables use the `EXPLORATOR_*` prefix. After upgrading Explorator, re-run the install with `--force` if you want the published config template refreshed from the package.

Alternatively, you can load the plugin in your `Application.php`:

```php
// In src/Application.php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('Crustum/Explorator');
}
```

If you prefer not to use PluginManifest, copy `vendor/crustum/explorator/config/explorator.php` to `config/explorator.php` and load it from bootstrap:

```php
if (file_exists(CONFIG . 'explorator.php')) {
    Configure::load('explorator', 'default');
}
```

A typical published configuration looks like:

```php
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
                'connection_timeout_seconds' => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            ],
            'model-settings' => [],
            'import_action' => env('TYPESENSE_IMPORT_ACTION', 'upsert'),
        ],
    ],
];
```

<a name="making-tables-searchable"></a>
### Making Tables Searchable

Add `SearchableTrait` to the Table you want to search, and attach the Searchable behavior so saves and deletes sync automatically. The trait is the public API; the behavior is events-only.

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Crustum\Explorator\Model\Trait\SearchableTrait;

class PostsTable extends Table
{
    use SearchableTrait;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->addBehavior('Crustum/Explorator.Searchable');
    }
}
```

On the entity, use `SearchableEntityTrait` and implement `toSearchableArray()` to control the indexed document:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;
use Crustum\Explorator\Model\Trait\SearchableEntityTrait;

class Post extends Entity
{
    use SearchableEntityTrait;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
```

Manual-index-only tables may use the trait without the behavior.

<a name="queueing"></a>
### Queueing

When using an engine that is not the `database` or `collection` engine, you should strongly consider configuring CakePHP Queue before using Explorator in production. Running a queue worker allows Explorator to queue operations that sync entity data to your search indexes, providing much better response times for your application's web interface.

Once you have configured a queue driver, set the value of the `queue` option in your Explorator configuration to `true`:

```php
'queue' => true,
```

Even when the `queue` option is set to `false`, it is important to remember that drivers such as Algolia and Meilisearch still apply writes asynchronously inside their own services. In other words, even though the index operation has completed within your CakePHP application, the search engine itself may not reflect the new and updated records immediately. See [Waiting for Engine Tasks](#waiting-for-engine-tasks).

To specify the connection and queue that Explorator jobs utilize, you may define the `queue` configuration option as an array:

```php
'queue' => [
    'connection' => 'default',
    'queue' => 'explorator',
],
```

If you customize the connection and queue, run a queue worker for that connection and queue name.

You may also set `Explorator.after_commit` to `true` so index sync runs after the database transaction commits successfully.

<a name="waiting-for-engine-tasks"></a>
### Waiting for Engine Tasks

Set `Explorator.wait_for_tasks` (environment variable `EXPLORATOR_WAIT_FOR_TASKS`) to `true` when integration tests or scripts must block until Algolia or Meilisearch tasks finish. The default is `false`.

Typesense writes are synchronous over HTTP, so the flag is a no-op for Typesense. Meilisearch `flush` always waits for `deleteAllDocuments` to complete, even when `wait_for_tasks` is false.

<a name="driver-prerequisites"></a>
## Driver Prerequisites

<a name="algolia"></a>
### Algolia

When using the Algolia driver, configure your Algolia `id` and `secret` credentials under `Explorator.algolia`. Once your credentials have been configured, install the Algolia PHP SDK (v4) via Composer:

```bash
composer require algolia/algoliasearch-client-php
```

<a name="meilisearch"></a>
### Meilisearch

[Meilisearch](https://www.meilisearch.com) is a fast, open source search engine.

When using the Meilisearch driver you will need to install the Meilisearch PHP SDK and an HTTP factory via Composer:

```bash
composer require meilisearch/meilisearch-php http-interop/http-factory-guzzle
```

Then set the `EXPLORATOR_DRIVER` environment variable as well as your Meilisearch `host` and `key` credentials within your application's `.env` file:

```ini
EXPLORATOR_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=masterKey
```

For more information regarding Meilisearch, please consult the [Meilisearch documentation](https://docs.meilisearch.com/learn/getting_started/quick_start.html).

Ensure that you install a version of `meilisearch/meilisearch-php` that is compatible with your Meilisearch binary version by reviewing [Meilisearch's documentation regarding binary compatibility](https://github.com/meilisearch/meilisearch-php#-compatibility-with-meilisearch).

> [!WARNING]
> When upgrading Explorator on an application that utilizes Meilisearch, you should always [review any additional breaking changes](https://github.com/meilisearch/Meilisearch/releases) to the Meilisearch service itself.

<a name="typesense"></a>
### Typesense

[Typesense](https://typesense.org) is a lightning-fast, open source search engine and supports keyword search, semantic search, geo search, and vector search.

You can [self-host](https://typesense.org/docs/guide/install-typesense.html#option-2-local-machine-self-hosting) Typesense or use [Typesense Cloud](https://cloud.typesense.org).

To get started using Typesense with Explorator, install the Typesense PHP SDK via Composer:

```bash
composer require typesense/typesense-php
```

Then set the `EXPLORATOR_DRIVER` environment variable as well as your Typesense host and API key credentials within your application's `.env` file:

```ini
EXPLORATOR_DRIVER=typesense
TYPESENSE_API_KEY=masterKey
TYPESENSE_HOST=localhost
```

You may also optionally specify your installation's port, path, and protocol:

```ini
TYPESENSE_PORT=8108
TYPESENSE_PATH=
TYPESENSE_PROTOCOL=http
```

Additional settings and schema definitions for your Typesense collections can be found within your application's Explorator configuration under `Explorator.typesense`. For more information regarding Typesense, please consult the [Typesense documentation](https://typesense.org/docs/guide/#quick-start).

<a name="configuration"></a>
## Configuration

<a name="configuring-searchable-data"></a>
### Configuring Searchable Data

By default you should define what is persisted to the search index by overriding `toSearchableArray` on the entity:

```php
<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;
use Crustum\Explorator\Model\Trait\SearchableEntityTrait;

class Post extends Entity
{
    use SearchableEntityTrait;

    /**
     * Get the indexable data array for the entity.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
```

<a name="configuring-table-engines"></a>
#### Configuring Table Engines

When searching, Explorator will typically use the default search engine specified by `Explorator.driver`. However, the search engine for a particular table can be changed by overriding the `searchableUsing` method on the Table:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Model\Trait\SearchableTrait;

class UsersTable extends Table
{
    use SearchableTrait;

    /**
     * Get the engine used to index the table.
     *
     * @return \Crustum\Explorator\Engines\Engine
     */
    public function searchableUsing(): Engine
    {
        return (new EngineManager())->engine('meilisearch');
    }
}
```

<a name="database-and-collection-engines"></a>
## Database / Collection Engines

<a name="database-engine"></a>
### Database Engine

> [!WARNING]
> The database engine currently supports MySQL and PostgreSQL, both of which provide support for fast, full-text column indexing.

The `database` engine uses MySQL / PostgreSQL full-text indexes and `LIKE` clauses to search your existing database directly. For many applications, this is the simplest and most practical way to add search — no external service or additional infrastructure required.

To use the database engine, set the `EXPLORATOR_DRIVER` environment variable to `database`:

```ini
EXPLORATOR_DRIVER=database
```

Once configured, you may [define your searchable data](#configuring-searchable-data) and start [executing search queries](#searching) against your tables. Unlike third-party engines, the database engine requires no separate indexing step — it searches your database tables directly.

For the database engine, the keys returned by `toSearchableArray()` are used as SQL column references in the search `WHERE` clause. Unqualified keys (for example `label`) are prefixed with the searching table's alias. If you search across a joined association (via `newExploratorQuery()`), use the **association alias** in the key — the same alias that appears in the query join — not the physical table name:

```php
/**
 * @return array<string, mixed>
 */
public function toSearchableArray(): array
{
    return [
        'label' => $this->label,
        'Chirps.content' => (string)($this->chirp->content ?? ''),
    ];
}
```

#### Customizing Database Searching Strategies

By default, the database engine will execute a `LIKE` query against every entity attribute that you have [configured as searchable](#configuring-searchable-data). However, you can assign more efficient search strategies to specific columns. The `SearchUsingFullText` attribute will use your database's full-text index for that column, while `SearchUsingPrefix` will only match the beginning of strings (`example%`) instead of searching within the entire string (`%example%`).

To define this behavior, assign PHP attributes to your entity's `toSearchableArray` method. Any columns without an attribute will continue to use the default `LIKE` strategy:

```php
use Crustum\Explorator\Attribute\SearchUsingFullText;
use Crustum\Explorator\Attribute\SearchUsingPrefix;

/**
 * Get the indexable data array for the entity.
 *
 * @return array<string, mixed>
 */
#[SearchUsingPrefix(['id', 'email'])]
#[SearchUsingFullText(['bio'])]
public function toSearchableArray(): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'email' => $this->email,
        'bio' => $this->bio,
    ];
}
```

> [!WARNING]
> Before specifying that a column should use full text query constraints, ensure that the column has been assigned a full text index in your schema.

<a name="collection-engine"></a>
### Collection Engine

The `collection` engine is intended for quick prototypes, extremely small datasets (a few hundred records), or running tests. It retrieves candidate records from your database and filters them in PHP, so it does not require any indexing or database-specific full-text features. For anything beyond trivial use cases, you should use the [database engine](#database-engine) instead.

To use the collection engine, set the value of the `EXPLORATOR_DRIVER` environment variable to `collection`, or specify the `collection` driver directly in your Explorator configuration:

```ini
EXPLORATOR_DRIVER=collection
```

Once you have specified the collection driver as your preferred driver, you may start [executing search queries](#searching) against your tables. Search engine indexing, such as the indexing needed to seed Algolia, Meilisearch, or Typesense indexes, is unnecessary when using the collection engine.

#### Differences From Database Engine

While the database engine uses full-text indexes and `LIKE` clauses to find matching records efficiently, the collection engine pulls records and filters them in PHP. The collection engine is the most portable option as it works across relational databases supported by CakePHP (including SQLite); however, it is significantly less efficient than the database engine and should not be used with large datasets.

<a name="third-party-engine-configuration"></a>
## Third-Party Engine Configuration

The following configuration options are only relevant when using a third-party search engine such as Algolia, Meilisearch, or Typesense. If you are using the [database engine](#database-engine), you may skip this section.

<a name="configuring-table-indexes"></a>
### Configuring Table Indexes

When using a third-party engine, each searchable Table is synced with a given search "index", which contains all of the searchable records for that table. By default, each table will be persisted to an index matching the Explorator prefix plus the table name. You are free to customize the index used when searching by overriding the `searchableAs` method on the Table:

```php
<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Crustum\Explorator\Model\Trait\SearchableTrait;

class PostsTable extends Table
{
    use SearchableTrait;

    /**
     * Get the name of the index associated with the table (search).
     *
     * @return string
     */
    public function searchableAs(): string
    {
        return 'posts_index';
    }
}
```

Override `indexableAs` when the index used for **writes** (add, update, delete, flush) should differ from the index used for **search**. By default `indexableAs()` returns `searchableAs()`:

```php
/**
 * Get the name of the index used when writing documents.
 *
 * @return string
 */
public function indexableAs(): string
{
    return 'posts_index_v2';
}
```

> [!NOTE]
> The `searchableAs` and `indexableAs` methods have no effect when using the database engine, which always searches the table's database rows directly.

<a name="configuring-the-document-key"></a>
#### Configuring the Document Key

By default, Explorator will use the primary key of the entity as the unique ID / key that is stored in the search index. If you need to customize this behavior when using a third-party engine, you may override the `getExploratorKey` and `getExploratorKeyName` methods on the entity (and the key name on the Table when needed):

```php
/**
 * Get the value used to index the entity.
 *
 * @return mixed
 */
public function getExploratorKey(): mixed
{
    return $this->email;
}

/**
 * Get the key name used to index the entity.
 *
 * @return string
 */
public function getExploratorKeyName(): string
{
    return 'email';
}
```

> [!NOTE]
> The `getExploratorKey` and `getExploratorKeyName` methods have no effect when using the database engine, which always uses the entity's primary key.

<a name="algolia-configuration"></a>
### Algolia

<a name="algolia-index-settings"></a>
#### Index Settings

Sometimes you may want to configure additional settings on your Algolia indexes. While you can manage these settings via the Algolia UI, it is sometimes more efficient to manage the desired state of your index configuration directly from your application's Explorator configuration file.

This approach allows you to deploy these settings through your application's automated deployment pipeline, avoiding manual configuration and ensuring consistency across multiple environments. You may configure filterable attributes, ranking, faceting, or [any other supported settings](https://www.algolia.com/doc/rest-api/search/#tag/Indices/operation/setSettings).

To get started, add settings for each index in your Explorator configuration, keyed by Table class:

```php
'algolia' => [
    'id' => env('ALGOLIA_APP_ID', ''),
    'secret' => env('ALGOLIA_SECRET', ''),
    'index-settings' => [
        \App\Model\Table\UsersTable::class => [
            'searchableAttributes' => ['id', 'name', 'email'],
            'attributesForFaceting' => ['filterOnly(email)'],
        ],
        \App\Model\Table\FlightsTable::class => [
            'searchableAttributes' => ['id', 'destination'],
        ],
    ],
],
```

If the table underlying a given index is soft deletable and is included in the `index-settings` array, Explorator will automatically include support for faceting on soft deleted records on that index. If you have no other faceting attributes to define for a soft deletable table index, you may simply add an empty entry to the `index-settings` array for that table:

```php
'index-settings' => [
    \App\Model\Table\FlightsTable::class => [],
],
```

After configuring your application's index settings, you must invoke the `explorator sync-index-settings` command. This command will inform Algolia of your currently configured index settings. For convenience, you may wish to make this command part of your deployment process:

```bash
bin/cake explorator sync-index-settings
```

<a name="algolia-identifying-users"></a>
#### Identifying Users

Explorator allows you to auto identify users when using Algolia. Associating the authenticated user with search operations may be helpful when viewing your search analytics within Algolia's dashboard. You can enable user identification by defining `EXPLORATOR_IDENTIFY` as `true` in your application's `.env` file:

```ini
EXPLORATOR_IDENTIFY=true
```

Enabling this feature will also pass the request's IP address and your authenticated user's primary identifier to Algolia so this data is associated with any search request that is made by the user, when supported by your application wiring.

<a name="meilisearch-configuration"></a>
### Meilisearch

<a name="meilisearch-index-settings"></a>
#### Index Settings

Meilisearch requires you to pre-define index search settings such as filterable attributes, sortable attributes, and [other supported settings fields](https://docs.meilisearch.com/reference/api/settings.html).

Filterable attributes are any attributes you plan to filter on when invoking Explorator's `where` method, while sortable attributes are any attributes you plan to sort by when invoking Explorator's `orderBy` method. To define your index settings, adjust the `index-settings` portion of your `meilisearch` configuration entry:

```php
'meilisearch' => [
    'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
    'key' => env('MEILISEARCH_KEY'),
    'index-settings' => [
        \App\Model\Table\UsersTable::class => [
            'filterableAttributes' => ['id', 'name', 'email'],
            'sortableAttributes' => ['created'],
        ],
        \App\Model\Table\FlightsTable::class => [
            'filterableAttributes' => ['id', 'destination'],
            'sortableAttributes' => ['modified'],
        ],
    ],
],
```

If the table underlying a given index is soft deletable and is included in the `index-settings` array, Explorator will automatically include support for filtering on soft deleted records on that index. If you have no other filterable or sortable attributes to define for a soft deletable table index, you may simply add an empty entry to the `index-settings` array for that table:

```php
'index-settings' => [
    \App\Model\Table\FlightsTable::class => [],
],
```

After configuring your application's index settings, you must invoke the `explorator sync-index-settings` command. This command will inform Meilisearch of your currently configured index settings. For convenience, you may wish to make this command part of your deployment process:

```bash
bin/cake explorator sync-index-settings
```

<a name="meilisearch-data-types"></a>
#### Searchable Data Types

Meilisearch will only perform filter operations (`>`, `<`, etc.) on data of the correct type. When customizing your searchable data, you should ensure that numeric values are cast to their correct type:

```php
public function toSearchableArray(): array
{
    return [
        'id' => (int)$this->id,
        'name' => $this->name,
        'price' => (float)$this->price,
    ];
}
```

<a name="typesense-configuration"></a>
### Typesense

<a name="typesense-searchable-data"></a>
#### Preparing Searchable Data

When utilizing Typesense, your searchable entities must define a `toSearchableArray` method that casts your entity's primary key to a string and creation date to a UNIX timestamp when those fields are indexed:

```php
/**
 * Get the indexable data array for the entity.
 *
 * @return array<string, mixed>
 */
public function toSearchableArray(): array
{
    return [
        'id' => (string)$this->id,
        'title' => $this->title,
        'created_at' => $this->created?->getTimestamp(),
    ];
}
```

You should also define your Typesense collection schemas in your application's Explorator configuration under `typesense.model-settings`. A collection schema describes the data types of each field that is searchable via Typesense. For more information on all available schema options, please consult the [Typesense documentation](https://typesense.org/docs/latest/api/collections.html#schema-parameters).

If you need to change your Typesense collection's schema after it has been defined, you may either run `bin/cake explorator flush` and `bin/cake explorator import`, which will delete all existing indexed data and recreate the schema. Or, you may use Typesense's API to modify the collection's schema without removing any indexed data.

If your searchable table uses Explorator soft delete, you should define a `__soft_deleted` field in the table's corresponding Typesense schema within your application's Explorator configuration:

```php
\App\Model\Table\UsersTable::class => [
    'collection-schema' => [
        'fields' => [
            [
                'name' => '__soft_deleted',
                'type' => 'int32',
                'optional' => true,
            ],
        ],
    ],
],
```

<a name="typesense-dynamic-search-parameters"></a>
#### Dynamic Search Parameters

Typesense allows you to modify your [search parameters](https://typesense.org/docs/latest/api/search.html#search-parameters) dynamically when performing a search operation via the `options` method:

```php
$results = $this->Todos->search('Groceries')
    ->options([
        'query_by' => 'title,description',
    ])
    ->get();
```

<a name="indexing"></a>
## Third-Party Engine Indexing

> [!NOTE]
> The indexing features described in this section are primarily relevant when using a third-party engine (Algolia, Meilisearch, or Typesense). The database engine searches your database tables directly, so it does not require manual index management.

<a name="batch-import"></a>
### Batch Import

If you are installing Explorator into an existing project, you may already have database records you need to import into your indexes. Explorator provides an `explorator import` command that you may use to import all of your existing records into your search indexes. Pass **table locator aliases** (for example `Posts`), not fully-qualified class names:

```bash
bin/cake explorator import Posts
```

The `explorator queue-import` command may be used to import all of your existing records using queued jobs:

```bash
bin/cake explorator queue-import Posts --chunk=500
```

The `flush` command may be used to remove all of a table's records from your search indexes:

```bash
bin/cake explorator flush Posts
```

From PHP you may also call:

```php
$this->Posts->importSearchable();
$this->Posts->importSearchable(
    $this->Posts->find()->where(['published' => true]),
    chunk: 500,
);
$this->Posts->flushSearchable();
```

<a name="modifying-the-import-query"></a>
#### Modifying the Import Query

If you would like to modify the query that is used to retrieve all of your entities for batch importing, pass a scoped `SelectQuery` into `importSearchable`. This is a great place to add any eager association loading that may be necessary before importing:

```php
$this->Posts->importSearchable(
    $this->Posts->find()->contain(['Authors']),
);
```

> [!WARNING]
> Eager-loaded associations may not be restored the same way when using a queue to batch import entities. Prefer embedding needed association data inside `toSearchableArray` / `makeSearchableUsing` when queue workers process chunks.

<a name="adding-records"></a>
### Adding Records

Once you have added `SearchableTrait` and the Searchable behavior to a Table, all you need to do is save an entity and it will automatically be added to your search index. If you have configured Explorator to [use queues](#queueing) this operation will be performed in the background by your queue worker:

```php
$post = $this->Posts->newEntity([
    'title' => 'Example',
    'body' => 'Hello world',
]);
$this->Posts->saveOrFail($post);
```

<a name="adding-records-via-query"></a>
#### Adding Records via Query

If you would like to add a set of entities to your search index via a query, call `importSearchable` with a `SelectQuery`. The method will chunk the results and add the records to your search index. Again, if you have configured Explorator to use queues, chunks will be imported in the background by your queue workers:

```php
$this->Posts->importSearchable(
    $this->Posts->find()->where(['price >' => 100]),
);
```

Or, if you already have a collection of entities in memory, you may call `makeSearchable` to add the entity instances to their corresponding index:

```php
$posts = $this->Posts->find()->where(['price >' => 100])->all();
$this->Posts->makeSearchable($posts);
```

> [!NOTE]
> `makeSearchable` / `importSearchable` can be considered an "upsert" operation. In other words, if the entity record is already in your index, it will be updated. If it does not exist in the search index, it will be added to the index.

<a name="updating-records"></a>
### Updating Records

To update a searchable entity, you only need to update the entity's properties and save it to your database. Explorator will automatically persist the changes to your search index:

```php
$post = $this->Posts->get(1);
$post = $this->Posts->patchEntity($post, [
    'title' => 'Updated title',
]);
$this->Posts->saveOrFail($post);
```

You may also invoke `importSearchable` or `makeSearchable` to update a collection of entities. If the entities do not exist in your search index, they will be created.

<a name="modifying-records-before-importing"></a>
#### Modifying Records Before Importing

Sometimes you may need to prepare the collection of entities before they are made searchable. For instance, you may want to eager load an association so that association data can be efficiently added to your search index. To accomplish this, define a `makeSearchableUsing` method on the entity:

```php
use Cake\Collection\CollectionInterface;

/**
 * Modify the collection of entities being made searchable.
 *
 * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
 * @return \Cake\Collection\CollectionInterface
 */
public function makeSearchableUsing(iterable $entities): CollectionInterface
{
    return collection($entities)->each(function ($entity): void {
        // prepare entity...
    });
}
```

<a name="conditionally-updating-the-search-index"></a>
#### Conditionally Updating the Search Index

By default, Explorator will reindex an updated entity regardless of which attributes were modified. If you would like to customize this behavior, you may define a `searchIndexShouldBeUpdated` method on your entity:

```php
/**
 * Determine if the search index should be updated.
 *
 * @return bool
 */
public function searchIndexShouldBeUpdated(): bool
{
    return $this->isNew() || $this->isDirty('title') || $this->isDirty('body');
}
```

<a name="removing-records"></a>
### Removing Records

To remove a record from your index you may simply delete the entity from the database. This may be done even if you are using soft deleted entities:

```php
$post = $this->Posts->get(1);
$this->Posts->deleteOrFail($post);
```

If you do not want to delete the database row, you may remove documents from the index with `removeFromSearch`, or remove a query chunk with `flushSearchable`:

```php
$this->Posts->removeFromSearch($posts);

$this->Posts->flushSearchable(
    $this->Posts->find()->where(['price >' => 100]),
);
```

To remove all of a table's records from their corresponding index, invoke `flushSearchable()` without a query, or run `bin/cake explorator flush Posts`.

<a name="pausing-indexing"></a>
### Pausing Indexing

Sometimes you may need to perform a batch of ORM operations on a table without syncing entity data to your search index. You may do this using the `withoutSyncingToSearch` method. This method accepts a single closure which will be immediately executed. Any table operations that occur within the closure will not be synced to the table's index:

```php
$this->Posts->withoutSyncingToSearch(function (): void {
    // Perform table actions...
});
```

<a name="conditionally-searchable-entities"></a>
### Conditionally Searchable Entities

Sometimes you may need to only make an entity searchable under certain conditions. For example, imagine you have a `Post` entity that may be in one of two states: "draft" and "published". You may only want to allow "published" posts to be searchable. To accomplish this, you may define a `shouldBeSearchable` method on your entity:

```php
/**
 * Determine if the entity should be searchable.
 *
 * @return bool
 */
public function shouldBeSearchable(): bool
{
    return $this->status === 'published';
}
```

The `shouldBeSearchable` method is only applied when manipulating entities through save and delete behavior hooks. Directly making entities searchable using `makeSearchable` will override the result of the `shouldBeSearchable` method.

> [!WARNING]
> The `shouldBeSearchable` method is not applicable when using Explorator's `database` engine, as all searchable data is always stored in the database. To achieve similar behavior when using the database engine, you should use [where clauses](#where-clauses) instead.

<a name="searching"></a>
## Searching

You may begin searching a table using the `search` method. The search method accepts a single string that will be used to search your entities. You should then chain the `get` method onto the search query to retrieve the entities that match the given search query:

```php
$posts = $this->Posts->search('Star Trek')->get();
```

`$table->search($q)` returns a `Crustum\Explorator\Builder` (not a Cake ORM `SelectQuery`). Terminate with `get()`, `first()`, `paginate()`, `raw()`, or `keys()`.

If you would like to get the raw search results before they are converted to entities, you may use the `raw` method:

```php
$raw = $this->Posts->search('Star Trek')->raw();
```

From a controller action you may return search results as JSON:

```php
public function search(): \Cake\Http\Response
{
    $q = (string)$this->request->getQuery('search', '');
    $posts = $this->Posts->search($q)->get();

    return $this->response
        ->withType('application/json')
        ->withStringBody(json_encode($posts));
}
```

<a name="custom-indexes"></a>
#### Custom Indexes

When searching using third-party engines, search queries will typically be performed on the index specified by the table's [searchableAs](#configuring-table-indexes) method. However, you may use the `within` method to specify a custom index that should be searched instead:

```php
$posts = $this->Posts->search('Star Trek')
    ->within('posts_popularity_desc')
    ->get();
```

<a name="where-clauses"></a>
### Where Clauses

Explorator allows you to add "where" clauses to your search queries. For example, basic equality checks are useful for scoping search queries by an owner ID:

```php
$posts = $this->Posts->search('Star Trek')->where('user_id', 1)->get();
```

You may also use the `=`, `!=`, `<`, `>`, `>=`, `<=` comparison operators to build more advanced queries:

```php
$posts = $this->Posts->search('Star Trek')
    ->where('status', '=', 'completed')
    ->where('is_refunded', '!=', true)
    ->where('total_price', '>', 100)
    ->where('shipping_cost', '<', 20)
    ->where('discount_percent', '>=', 10)
    ->where('item_count', '<=', 5)
    ->get();
```

In addition, the `whereIn` method may be used to verify that a given column's value is contained within the given array:

```php
$posts = $this->Posts->search('Star Trek')->whereIn(
    'status',
    ['open', 'paid'],
)->get();
```

The `whereNotIn` method verifies that the given column's value is not contained in the given array:

```php
$posts = $this->Posts->search('Star Trek')->whereNotIn(
    'status',
    ['closed'],
)->get();
```

> [!WARNING]
> If your application is using Meilisearch, you must configure your application's [filterable attributes](#meilisearch-index-settings) before utilizing Explorator's "where" clauses.

<a name="customizing-the-results-query"></a>
#### Customizing the Results Query

After Explorator retrieves a list of matching keys from your application's search engine, the ORM is used to retrieve all of the matching entities by those keys. You may customize this query by invoking the `query` method. The `query` method accepts a closure that will receive the Cake `SelectQuery` instance as an argument:

```php
$posts = $this->Posts->search('Star Trek')
    ->query(fn ($query) => $query->contain(['Authors']))
    ->get();
```

When using a third-party engine, this callback is invoked after the relevant entities have already been retrieved from the search engine, so it should not be used for "filtering" results — use [Explorator where clauses](#where-clauses) instead. However, when using the database engine, the `query` method's constraints are applied directly to the database query, so you may use it for filtering as well.

<a name="pagination"></a>
### Pagination

In addition to retrieving a collection of entities, you may paginate your search results using the `paginate` method. This method will return a Cake paginated result set:

```php
$posts = $this->Posts->search('Star Trek')->paginate();
```

You may specify how many entities to retrieve per page by passing the amount as the first argument to the `paginate` method:

```php
$posts = $this->Posts->search('Star Trek')->paginate(15);
```

When using the database engine, you may also use the `simplePaginate` method. Unlike `paginate`, which retrieves the total number of matching records so it can display page numbers, `simplePaginate` only determines whether there are more results beyond the current page — making it more efficient for large datasets where you only need "previous" and "next" links:

```php
$posts = $this->Posts->search('Star Trek')->simplePaginate(15);
```

From a controller you may return the paginator instance as JSON:

```php
public function index(): \Cake\Http\Response
{
    $q = (string)$this->request->getQuery('query', '');
    $posts = $this->Posts->search($q)->paginate(15);

    return $this->response
        ->withType('application/json')
        ->withStringBody(json_encode($posts));
}
```

> [!WARNING]
> Since search engines are not aware of your table's finder scopes, you should not rely on finders alone in applications that utilize Explorator pagination. Or, you should recreate the finder's constraints when searching via Explorator where clauses.

<a name="soft-deleting"></a>
### Soft Deleting

Soft delete support is **optional**. By default `Explorator.soft_delete` is `false` and you do not need Explorator's soft-delete behavior or search helpers.

When you want soft-deleted rows to stay searchable (with a flag) instead of being removed from the index on soft delete, enable soft delete in configuration and wire Explorator's ORM soft-delete stack.

#### Configuration

```php
'soft_delete' => true,
```

With this option enabled, searches automatically exclude soft-deleted documents by applying `__soft_deleted = 0` on the Explorator search builder. Soft-deleted entities keep a `__soft_deleted` value of `1` in the index instead of being deleted from the engine.

#### ORM Soft Delete Behavior

Attach `Crustum/Explorator.SoftDelete` on the Table and use `SoftDeleteTrait` on the entity. Soft delete uses a nullable datetime column named `deleted` (not another package's column name):

```php
// Table
$this->addBehavior('Crustum/Explorator.SoftDelete');
$this->addBehavior('Crustum/Explorator.Searchable');

// Entity
use Crustum\Explorator\Model\Trait\SoftDeleteTrait;
use Crustum\Explorator\Model\Trait\SearchableEntityTrait;

class Post extends Entity
{
    use SoftDeleteTrait;
    use SearchableEntityTrait;
}
```

The SoftDelete behavior also registers ORM finders:

```php
$posts = $this->Posts->find('withTrashed')->all();
$posts = $this->Posts->find('onlyTrashed')->all();
```

Those finders affect Cake ORM queries. They are separate from Explorator search builder methods.

#### Searching Soft Deleted Documents

On the search builder (when `soft_delete` is `true`):

```php
$posts = $this->Posts->search('Star Trek')->withTrashed()->get();
$posts = $this->Posts->search('Star Trek')->onlyTrashed()->get();
```

`SearchableEntityTrait` syncs `__soft_deleted` into the searchable payload from the `deleted` column. For Typesense, include an optional `__soft_deleted` int32 field in the collection schema when soft delete is enabled.

> [!NOTE]
> When a soft-deleted entity is permanently deleted, Explorator removes it from the search index automatically.

<a name="customizing-engine-searches"></a>
### Customizing Engine Searches

If you need to perform advanced customization of the search behavior of an engine you may pass a closure as the second argument to the `search` method. For example, you could use this callback to adjust options before the search query is passed to Algolia. Prefer `options()` and where clauses when they cover your case.

```php
$posts = $this->Posts->search(
    'Star Trek',
    function ($engine, string $query, array $options) {
        // Adjust engine-specific options...

        return $options;
    },
)->get();
```

<a name="custom-engines"></a>
## Custom Engines

<a name="writing-the-engine"></a>
#### Writing the Engine

If one of the built-in Explorator search engines doesn't fit your needs, you may write your own custom engine and register it with Explorator. Your engine should extend the `Crustum\Explorator\Engines\Engine` abstract class. This abstract class contains methods your custom engine must implement:

```php
use Cake\Collection\CollectionInterface;
use Cake\Datasource\ResultSetInterface;
use Crustum\Explorator\Builder;

abstract public function update(iterable $entities): void;
abstract public function delete(iterable $entities): void;
abstract public function search(Builder $builder): mixed;
abstract public function paginate(Builder $builder, int $perPage, int $page): mixed;
abstract public function mapIds(mixed $results): CollectionInterface;
abstract public function map(Builder $builder, mixed $results): ResultSetInterface;
abstract public function getTotalCount(mixed $results): int;
abstract public function flush(mixed $table): void;
```

You may find it helpful to review the implementations of these methods on the built-in Algolia or Meilisearch engine classes. Those classes will provide you with a good starting point for learning how to implement each of these methods in your own engine.

<a name="registering-the-engine"></a>
#### Registering the Engine

Once you have written your custom engine, you may register it with Explorator using the `extend` method of the `EngineManager`. You should call the `extend` method during application bootstrap (for example in your plugin or `Application` class):

```php
use App\Explorator\MysqlSearchEngine;
use Crustum\Explorator\EngineManager;

$manager = new EngineManager();
$manager->extend('mysql', function () {
    return new MysqlSearchEngine();
});
```

Once your engine has been registered, you may specify it as your default Explorator `driver` in your application's Explorator configuration file:

```php
'driver' => 'mysql',
```

Or return it from a table's `searchableUsing()` method.

<a name="console-commands"></a>
## Console Commands

Explorator registers Cake console commands under the `explorator` prefix. Pass **table locator aliases** (for example `Posts` or `SearchableUsers`) to import / flush commands — not fully-qualified PHP class names. Index create / delete commands take the **engine index name**.

List commands with `bin/cake` or `bin/cake explorator --help` (depending on your Cake version / plugin command discovery).

<a name="importing-records"></a>
### Importing Records

Import all searchable rows for a table into the configured engine:

```bash
bin/cake explorator import Posts
bin/cake explorator import Posts --fresh
bin/cake explorator import Posts --chunk=500
```

| Option | Description |
|--------|-------------|
| `--fresh` | Flush the table's index before importing |
| `--chunk` / `-c` | Batch size for the import |

For large datasets, queue chunked range jobs instead of importing in the CLI process:

```bash
bin/cake explorator queue-import Posts
bin/cake explorator queue-import Posts --chunk=500 --order=asc
bin/cake explorator queue-import Posts --min=1 --max=10000 --queue=search
```

| Option | Description |
|--------|-------------|
| `--chunk` / `-c` | Primary-key range size per `MakeRangeSearchable` job |
| `--min` / `--max` | Limit the explorator key range |
| `--order` | `asc` or `desc` (default `asc`) |
| `--queue` | Queue name for the range jobs |

Workers must be running for `queue-import` to apply. See also [Batch Import](#batch-import) for the matching Table APIs (`importSearchable`, `flushSearchable`).

<a name="flushing-indexes"></a>
### Flushing Indexes

Remove all documents for a searchable table from the engine:

```bash
bin/cake explorator flush Posts
```

<a name="managing-indexes"></a>
### Managing Indexes

Create or delete engine indexes by name (third-party engines):

```bash
bin/cake explorator index posts
bin/cake explorator index posts --key=id
bin/cake explorator delete-index posts
bin/cake explorator delete-all-indexes
```

| Command | Description |
|---------|-------------|
| `explorator index <name>` | Create an index (`--key` / `-k` optional primary key name) |
| `explorator delete-index <name>` | Delete one index |
| `explorator delete-all-indexes` | Delete all indexes when the engine supports it |

<a name="syncing-index-settings"></a>
### Syncing Index Settings

Push configured index settings (filterable attributes, collection schema extras, and similar) to Algolia / Meilisearch / Typesense when the driver implements settings updates:

```bash
bin/cake explorator sync-index-settings
bin/cake explorator sync-index-settings --driver=meilisearch
```

| Option | Description |
|--------|-------------|
| `--driver` | Engine driver name (defaults to `Explorator.driver`) |

Run this **before** relying on `where` / `whereIn` filters on Meilisearch, and after changing `Explorator.*.index-settings` in config.

<a name="command-reference"></a>
### Command Reference

| Command | Purpose |
|---------|---------|
| `explorator import <table>` | Import table records into the search index |
| `explorator queue-import <table>` | Import via chunked queued range jobs |
| `explorator flush <table>` | Remove all of the table's records from the index |
| `explorator index <name>` | Create a search index |
| `explorator delete-index <name>` | Delete a search index |
| `explorator delete-all-indexes` | Delete all indexes (engine-dependent) |
| `explorator sync-index-settings` | Sync configured index settings with the engine |

<a name="testing"></a>
## Testing

You may use the `\Crustum\Explorator\TestSuite\ExploratorTrait` to prevent remote engines from being contacted during testing. Typically, talking to Algolia, Meilisearch, or Typesense is unrelated to the code you are actually testing. Most likely, it is sufficient to simply assert that your application was instructed to index, unindex, or search.

After adding the `ExploratorTrait` to your test case, you may then assert that documents were written and even inspect the searchable payload:

```php
<?php
namespace App\Test\TestCase;

use Cake\TestSuite\TestCase;
use Crustum\Explorator\TestSuite\ExploratorTrait;

class PostTest extends TestCase
{
    use ExploratorTrait;

    protected array $fixtures = ['app.Posts'];

    public function testPostIndexedOnSave(): void
    {
        $posts = $this->getTableLocator()->get('Posts');

        $post = $posts->newEntity([
            'title' => 'Star Trek',
            'body' => 'These are the voyages…',
        ]);
        $posts->save($post);

        $this->assertIndexed('Posts');

        $this->assertIndexed('Posts', $post->id);

        $this->assertIndexedPayloadContains('Posts', 'title', 'Star Trek', $post->id);

        $this->assertIndexedTimes('Posts', 1);

        $this->assertWriteCount(1);
    }
}
```

When you use the `ExploratorTrait`, all index writes and searches are captured by `TestEngine` instead of being sent to a remote driver, allowing you to make assertions. The trait provides several helper methods to inspect captured operations:

```php
public function testIndexDetails(): void
{
    $posts = $this->getTableLocator()->get('Posts');
    $posts->save($posts->newEntity(['title' => 'Example']));

    $operations = $this->getExploratorOperationsForTable('Posts');
    $this->assertCount(1, $operations);

    $operation = $operations[0];
    $this->assertSame('update', $operation['operation']);
    $this->assertSame('Example', $operation['payloads'][0]['title']);
}
```

<a name="asserting-index-writes"></a>
### Asserting Index Writes

You can assert that specific tables were indexed:

```php
public function testMultipleIndexes(): void
{
    $posts = $this->getTableLocator()->get('Posts');
    $posts->save($posts->newEntity(['title' => 'One']));
    $posts->save($posts->newEntity(['title' => 'Two']));

    $this->assertIndexed('Posts');
    $this->assertIndexedTimes('Posts', 2);
    $this->assertNotIndexed('Comments');
}
```

Assert updates were recorded a specific number of times:

```php
public function testWriteCount(): void
{
    $posts = $this->getTableLocator()->get('Posts');
    $posts->save($posts->newEntity(['title' => 'One']));
    $posts->save($posts->newEntity(['title' => 'Two']));
    $posts->save($posts->newEntity(['title' => 'Three']));

    $this->assertIndexedTimes('Posts', 3);
    $this->assertWriteCount(3);
}
```

Or verify no index writes occurred:

```php
public function testNothingWrittenWhenSyncingPaused(): void
{
    $posts = $this->getTableLocator()->get('Posts');

    $posts->withoutSyncingToSearch(function () use ($posts): void {
        $posts->save($posts->newEntity(['title' => 'Silent']));
    });

    $this->assertNothingWritten();
}
```

`assertNothingWritten` and `assertWriteCount` count `update`, `delete`, and `flush` only — not search or paginate.

<a name="asserting-removals-and-searches"></a>
### Asserting Removals and Searches

You can assert that entities were removed from the search index, or that a search was performed:

```php
public function testRemovedFromSearch(): void
{
    $posts = $this->getTableLocator()->get('Posts');
    $post = $posts->save($posts->newEntity(['title' => 'Temporary']));
    \Crustum\Explorator\TestSuite\TestEngine::clearOperations();

    $posts->delete($post);

    $this->assertRemovedFromSearch('Posts', $post->id);
}

public function testSearchPerformed(): void
{
    $posts = $this->getTableLocator()->get('Posts');
    $posts->search('Star Trek')->get();

    $this->assertSearchPerformed('Star Trek', 'Posts');
    $this->assertSearchCount(1);
}
```

You may also assert a flush with `assertFlushed('Posts')`.

When `Explorator.soft_delete` is enabled, a soft delete is an **update** with `__soft_deleted = 1` in the searchable payload — use `assertIndexed` / `assertIndexedPayloadContains`, not `assertRemovedFromSearch`.

<a name="inspecting-indexed-content"></a>
### Inspecting Indexed Content

Sometimes you need to verify the specific content contained in an indexed document. The `ExploratorTrait` provides methods to retrieve and inspect captured operations:

```php
public function testIndexedContainsCorrectData(): void
{
    $posts = $this->getTableLocator()->get('Posts');
    $post = $posts->save($posts->newEntity([
        'title' => 'Star Trek',
        'body' => 'Space…',
    ]));

    $this->assertIndexedPayloadContains('Posts', 'title', 'Star Trek', $post->id);
    $this->assertIndexedPayloadContains('Posts', 'body', 'Space…');

    $operations = $this->getExploratorOperationsForTable('Posts');
    $payload = $operations[0]['payloads'][0];

    $this->assertSame('Star Trek', $payload['title']);
    $this->assertSame('Space…', $payload['body']);
}
```

Queue job asserts are **not** part of `ExploratorTrait`. When `Explorator.queue` is enabled, use cakephp/queue's `Cake\Queue\TestSuite\QueueTrait` (`assertJobQueued`, `assertJobQueuedWith`, …).

For end-to-end local search without capture asserts, you may still use the `null`, `collection`, or `database` drivers, or pause syncing with `withoutSyncingToSearch` / `SearchableBehavior::disableSyncingFor`.

<a name="available-assertions"></a>
### Available Assertions

The `ExploratorTrait` provides the following assertion methods for your tests:

| Method | Description |
|--------|-------------|
| `assertIndexed(string $table, mixed $key = null)` | Assert entities were indexed for a table / key |
| `assertUpdatedInSearch(string $table, mixed $key = null)` | Alias of `assertIndexed` |
| `assertIndexedAt(int $at, string $table, mixed $key = null)` | Assert an update at a specific capture index |
| `assertIndexedTimes(string $table, int $times, mixed $key = null)` | Assert updates were recorded N times |
| `assertNotIndexed(string $table, mixed $key = null)` | Assert a table / key was not indexed |
| `assertRemovedFromSearch(string $table, mixed $key = null)` | Assert entities were removed from search |
| `assertFlushed(string $table)` | Assert the table index was flushed |
| `assertNothingWritten()` | Assert no update / delete / flush occurred |
| `assertWriteCount(int $count)` | Assert the total number of write operations |
| `assertIndexedPayloadContains(string $table, string $key, mixed $value, mixed $exploratorKey = null)` | Assert indexed payload contains data |
| `assertSearchPerformed(?string $query = null, ?string $table = null)` | Assert a search or paginate was performed |
| `assertSearchNotPerformed()` | Assert no search was performed |
| `assertSearchCount(int $count)` | Assert the total number of searches |

Helper methods for retrieving captured operations:

| Method | Description |
|--------|-------------|
| `getExploratorOperations()` | Get all captured operations |
| `getExploratorWrites()` | Get update / delete / flush operations |
| `getExploratorSearches()` | Get search / paginate operations |
| `getExploratorOperationsForTable(string $table)` | Get operations for a table |
