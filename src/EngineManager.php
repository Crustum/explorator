<?php
declare(strict_types=1);

namespace Crustum\Explorator;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Cake\Core\Configure;
use Cake\Utility\Inflector;
use Crustum\Explorator\Engines\Algolia4Engine;
use Crustum\Explorator\Engines\CollectionEngine;
use Crustum\Explorator\Engines\DatabaseEngine;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Engines\MeilisearchEngine;
use Crustum\Explorator\Engines\NullEngine;
use Crustum\Explorator\Engines\TypesenseEngine;
use Crustum\Explorator\TestSuite\TestEngine;
use Exception;
use InvalidArgumentException;
use Meilisearch\Client as MeilisearchClient;
use Typesense\Client as TypesenseClient;

/**
 * Resolves Explorator engine drivers from Configure `Explorator` settings.
 */
class EngineManager
{
    /**
     * Resolved engine instances.
     *
     * @var array<string, \Crustum\Explorator\Engines\Engine>
     */
    protected array $drivers = [];

    /**
     * Custom driver creators.
     *
     * @var array<string, callable(): \Crustum\Explorator\Engines\Engine>
     */
    protected array $customCreators = [];

    /**
     * Get a Explorator engine instance.
     *
     * @param string|null $name Driver name
     * @return \Crustum\Explorator\Engines\Engine
     */
    public function engine(?string $name = null): Engine
    {
        return $this->driver($name);
    }

    /**
     * Get a driver instance.
     *
     * @param string|null $driver Driver name
     * @return \Crustum\Explorator\Engines\Engine
     */
    public function driver(?string $driver = null): Engine
    {
        $driver ??= $this->getDefaultDriver();

        return $this->drivers[$driver] ??= $this->createDriver($driver);
    }

    /**
     * Register a custom driver creator.
     *
     * @param string $driver Driver name
     * @param callable(): \Crustum\Explorator\Engines\Engine $callback Creator
     * @return $this
     */
    public function extend(string $driver, callable $callback)
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Forget all resolved engine instances.
     *
     * @return $this
     */
    public function forgetEngines()
    {
        $this->drivers = [];

        return $this;
    }

    /**
     * Get the default Explorator driver name.
     *
     * @return string
     */
    public function getDefaultDriver(): string
    {
        $driver = Configure::read('Explorator.driver');

        if ($driver === null || $driver === '') {
            return 'null';
        }

        return (string)$driver;
    }

    /**
     * Create a null engine instance.
     *
     * @return \Crustum\Explorator\Engines\NullEngine
     */
    public function createNullDriver(): NullEngine
    {
        return new NullEngine();
    }

    /**
     * Create the recording test engine (host / package TestSuite).
     *
     * @return \Crustum\Explorator\TestSuite\TestEngine
     */
    public function createTestDriver(): TestEngine
    {
        return new TestEngine();
    }

    /**
     * Create a collection engine instance.
     *
     * @return \Crustum\Explorator\Engines\CollectionEngine
     */
    public function createCollectionDriver(): CollectionEngine
    {
        return new CollectionEngine();
    }

    /**
     * Create a database engine instance.
     *
     * @return \Crustum\Explorator\Engines\DatabaseEngine
     */
    public function createDatabaseDriver(): DatabaseEngine
    {
        return new DatabaseEngine();
    }

    /**
     * Create an Algolia engine (v4 client).
     *
     * @return \Crustum\Explorator\Engines\Algolia4Engine
     * @throws \Exception
     */
    public function createAlgoliaDriver(): Algolia4Engine
    {
        if (!class_exists(SearchClient::class)) {
            throw new Exception('Please install algolia/algoliasearch-client-php (^4).');
        }

        /** @var array<string, mixed> $config */
        $config = Configure::read('Explorator.algolia', []);

        return Algolia4Engine::make(
            $config,
            [],
            (bool)Configure::read('Explorator.soft_delete', false),
        );
    }

    /**
     * Create a Meilisearch engine.
     *
     * @return \Crustum\Explorator\Engines\MeilisearchEngine
     * @throws \Exception
     */
    public function createMeilisearchDriver(): MeilisearchEngine
    {
        if (!class_exists(MeilisearchClient::class)) {
            throw new Exception('Please install meilisearch/meilisearch-php.');
        }

        /** @var array<string, mixed> $config */
        $config = Configure::read('Explorator.meilisearch', []);
        $client = new MeilisearchClient(
            (string)($config['host'] ?? 'http://localhost:7700'),
            $config['key'] ?? null,
        );

        return new MeilisearchEngine($client, (bool)Configure::read('Explorator.soft_delete', false));
    }

    /**
     * Create a Typesense engine.
     *
     * @return \Crustum\Explorator\Engines\TypesenseEngine
     * @throws \Exception
     */
    public function createTypesenseDriver(): TypesenseEngine
    {
        if (!class_exists(TypesenseClient::class)) {
            throw new Exception('Please install typesense/typesense-php.');
        }

        /** @var array<string, mixed> $config */
        $config = Configure::read('Explorator.typesense', []);
        /** @var array<string, mixed> $settings */
        $settings = $config['client-settings'] ?? [];

        return new TypesenseEngine(
            new TypesenseClient($settings),
            (int)($config['max_total_results'] ?? 1000),
            (bool)Configure::read('Explorator.soft_delete', false),
        );
    }

    /**
     * Create a new driver instance.
     *
     * @param string $driver Driver name
     * @return \Crustum\Explorator\Engines\Engine
     * @throws \InvalidArgumentException
     */
    protected function createDriver(string $driver): Engine
    {
        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])();
        }

        $method = 'create' . Inflector::camelize($driver) . 'Driver';
        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        throw new InvalidArgumentException(sprintf('Explorator driver [%s] is not supported.', $driver));
    }
}
