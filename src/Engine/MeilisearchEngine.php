<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engine;

use BackedEnum;
use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Closure;
use Crustum\Ai\Embeddings;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Contract\SupportsSemanticSearch;
use Crustum\Explorator\Contract\UpdatesIndexSettings;
use Crustum\Explorator\Exception\ExploratorException;
use Crustum\Explorator\Support\RemoveableExploratorCollection;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult;
use Override;

/**
 * Meilisearch Explorator engine (Cake rewrite).
 */
class MeilisearchEngine extends Engine implements SupportsSemanticSearch, UpdatesIndexSettings
{
    use LocatorAwareTrait;

    /**
     * @param \Meilisearch\Client $meilisearch Client
     * @param bool $softDelete Soft delete
     * @param array<string, mixed> $config Meilisearch engine configuration
     */
    public function __construct(
        protected MeilisearchClient $meilisearch,
        protected bool $softDelete = false,
        protected array $config = [],
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

        $records = [];
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

            $records[] = [
                'entity' => $entity,
                'object' => array_merge(
                    $searchableData,
                    $metadata,
                    [$keyName => $exploratorKey],
                ),
            ];
        }

        if ($records !== []) {
            $table = $this->getTableLocator()->get($first->getSource());

            $settings = $this->modelSettings($table);

            $embedding = isset($settings['embedding'])
                ? $this->embeddingSettings($table)
                : null;

            $objects = $embedding && !$this->usesNativeEmbeddings($embedding)
                ? $this->addEmbeddingsToRecords($records, $embedding)
                : array_column($records, 'object');

            $task = $index->addDocuments($objects, $keyName);
            $this->waitForMeilisearchTask($task);
        }
    }

    /**
     * Add generated embeddings to searchable records.
     *
     * @param list<array{entity: \Cake\Datasource\EntityInterface, object: array<string, mixed>}> $records Records
     * @param array<string, mixed> $settings Embedding settings
     * @return list<array<string, mixed>>
     */
    protected function addEmbeddingsToRecords(array $records, array $settings): array
    {
        $objects = [];

        foreach (array_chunk($records, 100) as $batch) {
            $inputs = [];
            $vectors = [];

            foreach ($batch as $index => $record) {
                if (!method_exists($record['entity'], 'toSearchableEmbedding')) {
                    throw new ExploratorException('Searchable entities using generated embeddings must define a [toSearchableEmbedding] method.');
                }

                $input = $record['entity']->toSearchableEmbedding();

                if (is_array($input)) {
                    $vectors[$index] = $input;

                    continue;
                }

                if (!is_string($input) || trim($input) === '') {
                    throw new ExploratorException('The [toSearchableEmbedding] method must return a non-empty string or an embedding array.');
                }

                $inputs[$index] = $input;
            }

            if ($inputs !== []) {
                $generatedVectors = $this->generateEmbeddings($inputs, $settings);

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generatedVectors[$position];
                }
            }

            foreach ($batch as $index => $record) {
                $object = $record['object'];

                if (isset($object['_vectors']) && !is_array($object['_vectors'])) {
                    throw new ExploratorException('The Meilisearch [_vectors] attribute must be an array.');
                }

                $object['_vectors'][$settings['embedder']] = $vectors[$index];
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * Remove entities from the index.
     *
     * @param \Crustum\Explorator\Support\RemoveableExploratorCollection|iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function delete(iterable $entities): void
    {
        if ($entities instanceof RemoveableExploratorCollection) {
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
        return $this->performSearch($builder, array_merge(array_filter([
            'filter' => $this->filters($builder) ?: null,
            'hitsPerPage' => $builder->limit,
            'sort' => $this->buildSortFromOrderByClauses($builder) ?: null,
        ]), $this->semanticSearchParameters($builder)));
    }

    /**
     * @inheritDoc
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        return $this->performSearch($builder, array_merge(array_filter([
            'filter' => $this->filters($builder) ?: null,
            'hitsPerPage' => $perPage,
            'page' => $page,
            'sort' => $this->buildSortFromOrderByClauses($builder) ?: null,
        ]), $this->semanticSearchParameters($builder)));
    }

    /**
     * Build semantic and hybrid search parameters.
     *
     * @return array<string, mixed>
     */
    protected function semanticSearchParameters(Builder $builder): array
    {
        if (!$builder->semanticSearch && $builder->hybridSearch === null) {
            return [];
        }

        if (array_key_exists('hybrid', $builder->options)) {
            throw new ExploratorException('Meilisearch semantic and hybrid searches cannot be combined with a custom [hybrid] option.');
        }

        $settings = $this->embeddingSettings($builder->table);

        $vector = $builder->options['vector'] ?? null;

        if (!$this->usesNativeEmbeddings($settings)) {
            $vector ??= $this->generateEmbeddings([$builder->query], $settings)[0];
        }

        $semanticRatio = $builder->semanticSearch
            ? 1.0
            : $builder->hybridSearch['semantic_weight'] / array_sum($builder->hybridSearch);

        $parameters = [
            'hybrid' => [
                'embedder' => $settings['embedder'],
                'semanticRatio' => $semanticRatio,
            ],
        ];

        if (!is_null($vector)) {
            if (!is_array($vector) || $vector === []) {
                throw new ExploratorException('The Meilisearch query [vector] must be a non-empty embedding array.');
            }

            $parameters = [
                'vector' => $vector,
            ] + $parameters;
        }

        if (!is_null($builder->minimumSimilarity)) {
            $parameters['rankingScoreThreshold'] = $builder->minimumSimilarity;
        }

        return $parameters;
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
     * Get the configured settings for a model.
     *
     * @param object $model Model entity or table
     * @return array<string, mixed>
     */
    protected function modelSettings(object $model): array
    {
        return $this->config['model-settings'][$model::class] ?? [];
    }

    /**
     * Get the validated embedding settings for a model.
     *
     * @param object $model Model entity or table
     * @return array<string, mixed>
     */
    protected function embeddingSettings(object $model): array
    {
        $settings = $this->modelSettings($model)['embedding'] ?? null;

        if (!is_array($settings)) {
            throw new ExploratorException('No Meilisearch embedding settings have been configured for [' . $model::class . '].');
        }

        if (!isset($settings['embedder']) || !is_string($settings['embedder']) || trim($settings['embedder']) === '') {
            throw new ExploratorException('Meilisearch embedding settings must contain an [embedder].');
        }

        $driver = $settings['driver'] ?? 'crustum-ai';

        if (!in_array($driver, ['crustum-ai', 'meilisearch'], true)) {
            throw new ExploratorException("The [{$driver}] Meilisearch embedding driver is not supported.");
        }

        $settings['driver'] = $driver;

        if ($this->usesNativeEmbeddings($settings)) {
            return $settings;
        }

        if (
            !isset($settings['dimensions']) ||
            filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false ||
            $settings['dimensions'] < 1
        ) {
            throw new ExploratorException('Meilisearch embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int)$settings['dimensions'];

        return $settings;
    }

    /**
     * Determine if Meilisearch should generate embeddings natively.
     *
     * @param array<string, mixed> $settings Embedding settings
     * @return bool
     */
    protected function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'meilisearch';
    }

    /**
     * Generate and validate embeddings using the Crustum AI package.
     *
     * @param list<string> $inputs Input texts
     * @param array<string, mixed> $settings Embedding settings
     * @return list<array<int, float>>
     */
    protected function generateEmbeddings(array $inputs, array $settings): array
    {
        if (!class_exists(Embeddings::class)) {
            throw new ExploratorException('Semantic search requires the Crustum AI package (crustum/cakephp-ai). Install it or return a precomputed embedding array from [toSearchableEmbedding()].');
        }

        $response = Embeddings::for($inputs)
            ->dimensions($settings['dimensions'])
            ->cache()
            ->generate($settings['provider'] ?? null, $settings['model'] ?? null);

        $embeddings = $response->embeddings;

        if (count($embeddings) !== count($inputs)) {
            throw new ExploratorException('The embeddings provider returned an unexpected number of embeddings.');
        }

        return $embeddings;
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
