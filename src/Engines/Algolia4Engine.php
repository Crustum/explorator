<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engines;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Job\RemovableExploratorCollection;
use Override;

/**
 * Algolia v4 Explorator engine.
 */
class Algolia4Engine extends AlgoliaEngine
{
    use LocatorAwareTrait;

    /**
     * @param \Algolia\AlgoliaSearch\Api\SearchClient $algolia Client
     * @param bool $softDelete Soft delete flag
     */
    public function __construct(SearchClient $algolia, bool $softDelete = false)
    {
        parent::__construct($algolia, $softDelete);
    }

    /**
     * @param array<string, mixed> $config Explorator Algolia config
     * @param array<string, string> $headers Headers
     * @param bool $softDelete Soft delete
     * @return self
     */
    public static function make(array $config, array $headers = [], bool $softDelete = false): self
    {
        unset($headers);
        $client = SearchClient::create(
            (string)($config['id'] ?? ''),
            (string)($config['secret'] ?? ''),
        );

        return new self($client, $softDelete);
    }

    /**
     * @inheritDoc
     */
    public function update(iterable $entities): void
    {
        $list = collection($entities)->toList();
        if ($list === []) {
            return;
        }

        /** @var \Cake\Datasource\EntityInterface $first */
        $first = $list[0];
        $index = $this->resolveIndexName($first);

        if ($this->usesSoftDelete($first) && $this->softDelete) {
            foreach ($list as $entity) {
                if (method_exists($entity, 'pushSoftDeleteMetadata')) {
                    $entity->pushSoftDeleteMetadata();
                }
            }
        }

        $objects = [];
        foreach ($list as $entity) {
            $payload = method_exists($entity, 'toSearchableArray')
                ? $entity->toSearchableArray()
                : $entity->toArray();
            if ($payload === []) {
                continue;
            }

            $metadata = method_exists($entity, 'exploratorMetadata') ? $entity->exploratorMetadata() : [];
            $key = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get('id');
            $objects[] = array_merge($payload, $metadata, ['objectID' => $key]);
        }

        if ($objects !== []) {
            /** @var \Algolia\AlgoliaSearch\Api\SearchClient $client */
            $client = $this->algolia;
            $client->saveObjects($index, $objects, $this->shouldWaitForTasks());
        }
    }

    /**
     * Remove entities from the index.
     *
     * @param \Crustum\Explorator\Job\RemovableExploratorCollection|iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function delete(iterable $entities): void
    {
        if ($entities instanceof RemovableExploratorCollection) {
            $rows = $entities->toList();
            if ($rows === []) {
                return;
            }

            $table = $this->getTableLocator()->get((string)$rows[0]['source']);
            $index = $this->indexName($table);
            $ids = $entities->exploratorKeys();
            /** @var \Algolia\AlgoliaSearch\Api\SearchClient $client */
            $client = $this->algolia;
            $client->deleteObjects($index, $ids, $this->shouldWaitForTasks());

            return;
        }

        $list = collection($entities)->toList();
        if ($list === []) {
            return;
        }

        $index = $this->resolveIndexName($list[0]);
        $ids = [];
        foreach ($list as $entity) {
            $ids[] = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get('id');
        }

        /** @var \Algolia\AlgoliaSearch\Api\SearchClient $client */
        $client = $this->algolia;
        $client->deleteObjects($index, $ids, $this->shouldWaitForTasks());
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function flush(mixed $table): void
    {
        if (!$table instanceof Table) {
            return;
        }

        $index = $this->indexName($table);
        /** @var \Algolia\AlgoliaSearch\Api\SearchClient $client */
        $client = $this->algolia;
        $response = $client->clearObjects($index);
        $this->waitForAlgoliaTask($index, $response);
    }

    /**
     * @inheritDoc
     */
    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $index = $builder->index ?? $this->indexName($builder->table);
        /** @var \Algolia\AlgoliaSearch\Api\SearchClient $client */
        $client = $this->algolia;

        return $client->searchSingleIndex($index, array_filter([
            'query' => $builder->query,
            'filters' => $options['filters'] ?? null,
            'numericFilters' => $options['numericFilters'] ?? null,
            'hitsPerPage' => $options['hitsPerPage'] ?? null,
            'page' => $options['page'] ?? null,
        ]));
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        unset($name, $options);

        return null;
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $name): mixed
    {
        /** @var \Algolia\AlgoliaSearch\Api\SearchClient $client */
        $client = $this->algolia;
        $response = $client->deleteIndex($name);
        $this->waitForAlgoliaTask($name, $response);

        return $response;
    }

    /**
     * @inheritDoc
     */
    public function updateIndexSettings(string $name, array $settings = []): void
    {
        /** @var \Algolia\AlgoliaSearch\Api\SearchClient $client */
        $client = $this->algolia;
        $response = $client->setSettings($name, $settings);
        $this->waitForAlgoliaTask($name, $response);
    }

    /**
     * Wait for an Algolia index task when Explorator.wait_for_tasks is enabled.
     *
     * @param string $index Index name
     * @param mixed $response API response that may contain taskID
     * @return void
     */
    protected function waitForAlgoliaTask(string $index, mixed $response): void
    {
        if (!$this->shouldWaitForTasks() || !is_array($response)) {
            return;
        }

        $taskId = $response['taskID'] ?? null;
        if ($taskId === null) {
            return;
        }

        /** @var \Algolia\AlgoliaSearch\Api\SearchClient $client */
        $client = $this->algolia;
        $client->waitForTask($index, $taskId);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return string
     */
    protected function resolveIndexName(EntityInterface $entity): string
    {
        $source = $entity->getSource();
        if ($source === '') {
            return 'default';
        }

        $table = $this->getTableLocator()->get($source);
        if (method_exists($table, 'indexableAs')) {
            return $table->indexableAs();
        }

        if (method_exists($table, 'searchableAs')) {
            return $table->searchableAs();
        }

        return $table->getTable();
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return bool
     */
    protected function usesSoftDelete(EntityInterface $entity): bool
    {
        $source = $entity->getSource();
        if ($source === '') {
            return false;
        }

        return $this->getTableLocator()->get($source)->hasBehavior('SoftDelete');
    }
}
