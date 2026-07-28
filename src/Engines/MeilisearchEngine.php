<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engines;

use BackedEnum;
use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Closure;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Contract\UpdatesIndexSettings;
use Crustum\Explorator\Job\RemovableExploratorCollection;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult;
use Override;

/**
 * Meilisearch Explorator engine (Cake rewrite).
 */
class MeilisearchEngine extends Engine implements UpdatesIndexSettings
{
    use LocatorAwareTrait;

    /**
     * @param \Meilisearch\Client $meilisearch Client
     * @param bool $softDelete Soft delete
     */
    public function __construct(
        protected MeilisearchClient $meilisearch,
        protected bool $softDelete = false,
    ) {
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
        $indexName = $this->indexFor($first);
        $index = $this->meilisearch->index($indexName);

        if ($this->usesSoftDelete($first) && $this->softDelete) {
            foreach ($list as $entity) {
                if (method_exists($entity, 'pushSoftDeleteMetadata')) {
                    $entity->pushSoftDeleteMetadata();
                }
            }
        }

        $keyName = method_exists($first, 'getExploratorKeyName')
            ? $first->getExploratorKeyName()
            : 'id';
        $objects = [];
        foreach ($list as $entity) {
            $searchableData = method_exists($entity, 'toSearchableArray')
                ? $entity->toSearchableArray()
                : $entity->toArray();
            if ($searchableData === []) {
                continue;
            }

            $metadata = method_exists($entity, 'exploratorMetadata') ? $entity->exploratorMetadata() : [];
            $exploratorKey = method_exists($entity, 'getExploratorKey')
                ? $entity->getExploratorKey()
                : $entity->get($keyName);

            $objects[] = array_merge(
                $searchableData,
                $metadata,
                [$keyName => $exploratorKey],
            );
        }

        if ($objects !== []) {
            $task = $index->addDocuments($objects, $keyName);
            $this->waitForMeilisearchTask($task);
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
            $index = $this->indexableName($table);
            $task = $this->meilisearch->index($index)->deleteDocuments($entities->exploratorKeys());
            $this->waitForMeilisearchTask($task);

            return;
        }

        $list = collection($entities)->toList();
        if ($list === []) {
            return;
        }

        $index = $this->indexFor($list[0]);
        $ids = [];
        foreach ($list as $entity) {
            $ids[] = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get('id');
        }

        $task = $this->meilisearch->index($index)->deleteDocuments($ids);
        $this->waitForMeilisearchTask($task);
    }

    /**
     * @inheritDoc
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, array_filter([
            'filter' => $this->filters($builder) ?: null,
            'hitsPerPage' => $builder->limit,
            'sort' => $this->buildSortFromOrderByClauses($builder) ?: null,
        ]));
    }

    /**
     * @inheritDoc
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        return $this->performSearch($builder, array_filter([
            'filter' => $this->filters($builder) ?: null,
            'hitsPerPage' => $perPage,
            'page' => $page,
            'sort' => $this->buildSortFromOrderByClauses($builder) ?: null,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @param array<string, mixed> $searchParams Search params
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $searchParams = []): mixed
    {
        $meilisearch = $this->meilisearch->index($builder->index ?? $this->indexName($builder->table));

        $searchParams = array_merge($builder->options, $searchParams);

        if (array_key_exists('attributesToRetrieve', $searchParams)) {
            $keyName = method_exists($builder->table, 'getExploratorKeyName')
                ? $builder->table->getExploratorKeyName()
                : 'id';
            $searchParams['attributesToRetrieve'] = array_merge(
                [$keyName],
                (array)$searchParams['attributesToRetrieve'],
            );
        }

        if ($builder->callback instanceof Closure) {
            $result = ($builder->callback)($meilisearch, $builder->query, $searchParams);

            return $result instanceof SearchResult ? $result->getRaw() : $result;
        }

        return $meilisearch->rawSearch($builder->query, $searchParams);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Builder
     * @return string
     */
    protected function filters(Builder $builder): string
    {
        $parts = [];
        foreach ($builder->wheres as $where) {
            $field = $where['field'];
            $value = $where['value'];
            $operator = $where['operator'];

            if ($value instanceof BackedEnum) {
                $parts[] = sprintf('%s%s%s', $field, $operator, $value->value);
                continue;
            }

            if (is_bool($value)) {
                $parts[] = sprintf('%s%s%s', $field, $operator, $value ? 'true' : 'false');
                continue;
            }

            if ($value === null) {
                $parts[] = sprintf('%s %s', $field, $operator === '!=' ? 'IS NOT NULL' : 'IS NULL');
                continue;
            }

            $parts[] = is_numeric($value)
                ? sprintf('%s%s%s', $field, $operator, $value)
                : sprintf('%s%s"%s"', $field, $operator, $value);
        }

        foreach ($builder->whereIns as $key => $values) {
            $parts[] = sprintf('%s IN [%s]', $key, $this->formatFilterList($values));
        }

        foreach ($builder->whereNotIns as $key => $values) {
            $parts[] = sprintf('%s NOT IN [%s]', $key, $this->formatFilterList($values));
        }

        return implode(' AND ', $parts);
    }

    /**
     * @param list<mixed> $values Values
     * @return string
     */
    protected function formatFilterList(array $values): string
    {
        $formatted = [];
        foreach ($values as $value) {
            if (is_bool($value)) {
                $formatted[] = $value ? 'true' : 'false';
                continue;
            }

            $formatted[] = filter_var($value, FILTER_VALIDATE_INT) !== false
                ? (string)$value
                : sprintf('"%s"', $value);
        }

        return implode(', ', $formatted);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Builder
     * @return list<string>
     */
    protected function buildSortFromOrderByClauses(Builder $builder): array
    {
        $sort = [];
        foreach ($builder->orders as $order) {
            $sort[] = $order['column'] . ':' . $order['direction'];
        }

        return $sort;
    }

    /**
     * @inheritDoc
     */
    public function mapIds(mixed $results): CollectionInterface
    {
        $hits = $results['hits'] ?? [];
        if ($hits === []) {
            return collection([]);
        }

        $key = array_key_first($hits[0]);

        return $key !== null ? collection($hits)->extract((string)$key) : collection([]);
    }

    /**
     * Pluck hit values for a named primary key.
     *
     * @param mixed $results Raw results
     * @param string $key Key name
     * @return \Cake\Collection\CollectionInterface
     */
    #[Override]
    public function mapIdsFrom(mixed $results, string $key): CollectionInterface
    {
        $hits = is_array($results) ? ($results['hits'] ?? []) : [];
        if ($hits === []) {
            return collection([]);
        }

        return collection($hits)->extract($key);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function keys(Builder $builder): CollectionInterface
    {
        $keyName = method_exists($builder->table, 'getExploratorKeyName')
            ? $builder->table->getExploratorKeyName()
            : 'id';

        return $this->mapIdsFrom($this->search($builder), $keyName);
    }

    /**
     * @inheritDoc
     */
    public function map(Builder $builder, mixed $results): ResultSetInterface
    {
        $hits = $results['hits'] ?? [];
        if ($hits === []) {
            return new ResultSet([]);
        }

        $keyName = method_exists($builder->table, 'getExploratorKeyName')
            ? $builder->table->getExploratorKeyName()
            : 'id';
        $objectIds = collection($hits)->extract($keyName)->toList();
        $objectIdPositions = array_flip($objectIds);

        if (method_exists($builder->table, 'getExploratorModelsByIds')) {
            $models = $builder->table->getExploratorModelsByIds($builder, $objectIds)
                ->filter(function (EntityInterface $entity) use ($objectIds, $keyName): bool {
                    $key = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);

                    return in_array($key, $objectIds, true);
                })
                ->map(function (EntityInterface $entity) use ($results, $objectIdPositions, $keyName): EntityInterface {
                    $key = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);
                    $result = $results['hits'][$objectIdPositions[$key]] ?? [];

                    foreach ($result as $metaKey => $value) {
                        if (str_starts_with((string)$metaKey, '_') && method_exists($entity, 'withExploratorMetadata')) {
                            $entity->withExploratorMetadata((string)$metaKey, $value);
                        }
                    }

                    return $entity;
                })
                ->sortBy(function (EntityInterface $entity) use ($objectIdPositions, $keyName): int {
                    $key = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);

                    return $objectIdPositions[$key];
                }, SORT_ASC)
                ->toList();

            return new ResultSet($models);
        }

        return new ResultSet($builder->table->find()->whereInList($keyName, $objectIds)->all()->toList());
    }

    /**
     * @inheritDoc
     */
    public function lazyMap(Builder $builder, mixed $results): CollectionInterface
    {
        return collection($this->map($builder, $results)->toList());
    }

    /**
     * @inheritDoc
     */
    public function getTotalCount(mixed $results): int
    {
        return (int)($results['totalHits'] ?? $results['estimatedTotalHits'] ?? 0);
    }

    /**
     * @inheritDoc
     */
    public function flush(mixed $table): void
    {
        if (!$table instanceof Table) {
            return;
        }

        $task = $this->meilisearch->index($this->indexableName($table))->deleteAllDocuments();
        $this->waitForMeilisearchTask($task, force: true);
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        try {
            $index = $this->meilisearch->getIndex($name);
        } catch (ApiException) {
            $index = null;
        }

        if ($index?->getUid() !== null) {
            return $index;
        }

        return $this->meilisearch->createIndex($name, $options);
    }

    /**
     * @inheritDoc
     */
    public function updateIndexSettings(string $name, array $settings = []): void
    {
        $index = $this->meilisearch->index($name);

        $indexSettings = $settings;
        unset($indexSettings['embedders']);

        $settingsTask = $index->updateSettings($indexSettings);
        $this->waitForMeilisearchTask($settingsTask);

        if (!empty($settings['embedders'])) {
            $embedderTask = $index->updateEmbedders($settings['embedders']);
            $this->waitForMeilisearchTask($embedderTask);
        }
    }

    /**
     * @inheritDoc
     */
    public function configureSoftDeleteFilter(array $settings = []): array
    {
        $settings['filterableAttributes'] ??= [];
        $settings['filterableAttributes'][] = '__soft_deleted';

        return $settings;
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $name): mixed
    {
        $task = $this->meilisearch->deleteIndex($name);
        $this->waitForMeilisearchTask($task);

        return $task;
    }

    /**
     * Wait for a Meilisearch task when Explorator.wait_for_tasks is enabled,
     * or when $force is true (flush always waits — prior Explorator behavior).
     *
     * @param mixed $task Task payload with taskUid
     * @param bool $force Wait even when wait_for_tasks is false
     * @return void
     */
    protected function waitForMeilisearchTask(mixed $task, bool $force = false): void
    {
        if (!$force && !$this->shouldWaitForTasks()) {
            return;
        }

        $taskUid = null;
        if (is_array($task)) {
            $taskUid = $task['taskUid'] ?? null;
        } elseif (is_object($task) && method_exists($task, 'getTaskUid')) {
            $taskUid = $task->getTaskUid();
        }

        if ($taskUid !== null) {
            $this->meilisearch->waitForTask($taskUid);
        }
    }

    /**
     * Delete all search indexes matching the configured prefix.
     *
     * @return list<mixed>
     */
    public function deleteAllIndexes(): array
    {
        $tasks = [];
        $query = new IndexesQuery();
        $query->setLimit(1000000);

        $indexes = $this->meilisearch->getIndexes($query);
        $prefix = (string)Configure::read('Explorator.prefix', '');

        foreach ($indexes->getResults() as $index) {
            $uid = (string)$index->getUid();
            if ($prefix === '' || str_starts_with($uid, $prefix)) {
                $tasks[] = $index->delete();
            }
        }

        return $tasks;
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

    /**
     * Index name used when searching.
     *
     * @param \Cake\ORM\Table $table Table
     * @return string
     */
    protected function indexName(Table $table): string
    {
        return method_exists($table, 'searchableAs') ? $table->searchableAs() : $table->getTable();
    }

    /**
     * Index name used when writing documents.
     *
     * @param \Cake\ORM\Table $table Table
     * @return string
     */
    protected function indexableName(Table $table): string
    {
        if (method_exists($table, 'indexableAs')) {
            return $table->indexableAs();
        }

        return $this->indexName($table);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return string
     */
    protected function indexFor(EntityInterface $entity): string
    {
        $source = $entity->getSource();
        if ($source === '') {
            return 'default';
        }

        return $this->indexableName($this->getTableLocator()->get($source));
    }

    /**
     * Dynamically call the Meilisearch client instance.
     *
     * @param string $method Method
     * @param array<int, mixed> $parameters Parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->meilisearch->{$method}(...$parameters);
    }
}
