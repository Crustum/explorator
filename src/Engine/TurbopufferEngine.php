<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engine;

use BackedEnum;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Closure;
use Crustum\Ai\Embeddings;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Contract\SupportsSemanticSearch;
use Crustum\Explorator\Exception\ExploratorException;
use Crustum\Explorator\Service\Turbopuffer\TurbopufferClient;
use Crustum\Explorator\Service\Turbopuffer\TurbopufferNamespace;
use function Cake\Collection\collection;

/**
 * Turbopuffer search engine (full-text + vector).
 */
class TurbopufferEngine extends Engine implements SupportsSemanticSearch
{
    /**
     * @param \Crustum\Explorator\Service\Turbopuffer\TurbopufferClient $turbopuffer Turbopuffer client
     * @param array<string, mixed> $config Engine configuration
     * @param bool $softDelete Whether soft-delete metadata is applied
     */
    public function __construct(
        protected TurbopufferClient $turbopuffer,
        protected array $config = [],
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

        $first = $list[0];
        $table = $this->getTableLocator()->get($first->getSource());

        if ($this->usesSoftDelete($first) && $this->softDelete) {
            foreach ($list as $entity) {
                if (method_exists($entity, 'pushSoftDeleteMetadata')) {
                    $entity->pushSoftDeleteMetadata();
                }
            }
        }

        $records = [];
        foreach ($list as $entity) {
            $searchableData = method_exists($entity, 'toSearchableArray')
                ? $entity->toSearchableArray()
                : $entity->toArray();

            if (empty($searchableData)) {
                continue;
            }

            $records[] = [
                'entity' => $entity,
                'row' => array_merge(
                    $searchableData,
                    method_exists($entity, 'exploratorMetadata') ? $entity->exploratorMetadata() : [],
                    ['id' => $this->exploratorKey($entity)],
                ),
            ];
        }

        if ($records === []) {
            return;
        }

        $settings = $this->modelSettings($table);

        $embeddingSettings = isset($settings['embedding'])
            ? $this->embeddingSettings($table)
            : null;

        $rows = $embeddingSettings && !$this->usesNativeEmbeddings($embeddingSettings)
            ? $this->addEmbeddingsToRecords($records, $embeddingSettings)
            : array_column($records, 'row');

        if ($embeddingSettings && $this->usesNativeEmbeddings($embeddingSettings)) {
            $rows = array_map(function (array $row) use ($embeddingSettings): array {
                unset($row[$embeddingSettings['generated_attribute']]);

                return $row;
            }, $rows);
        }

        $parameters = ['upsert_rows' => $rows];

        foreach (['schema', 'distance_metric'] as $option) {
            if (isset($settings[$option])) {
                $parameters[$option] = $settings[$option];
            }
        }

        if ($embeddingSettings && $this->usesNativeEmbeddings($embeddingSettings)) {
            $embed = &$parameters['schema'][$embeddingSettings['attribute']]['embed'];

            if (is_array($embed) && isset($embed['dimensions'])) {
                $embed['dims'] = (int)$embed['dimensions'];

                unset($embed['dimensions']);
            }
        }

        if (isset($settings['embedding']) && !isset($parameters['distance_metric'])) {
            $parameters['distance_metric'] = 'cosine_distance';
        }

        $this->turbopuffer->namespace(
            method_exists($table, 'indexableAs') ? $table->indexableAs() : $table->getTable(),
        )->write($parameters);
    }

    /**
     * Add generated embeddings to searchable records.
     *
     * @param list<array{entity: \Cake\Datasource\EntityInterface, row: array<string, mixed>}> $records Records
     * @param array<string, mixed> $settings Embedding settings
     * @return list<array<string, mixed>>
     */
    protected function addEmbeddingsToRecords(array $records, array $settings): array
    {
        $settings = $this->validateEmbeddingSettings($settings);

        $rows = [];

        foreach (array_chunk($records, 100) as $batch) {
            $inputs = [];
            $vectors = [];

            foreach ($batch as $index => $record) {
                if (!method_exists($record['entity'], 'toSearchableEmbedding')) {
                    throw new ExploratorException('Searchable entities using generated embeddings must define a [toSearchableEmbedding] method.');
                }

                $input = call_user_func([$record['entity'], 'toSearchableEmbedding']);

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
                $generatedVectors = $this->generateEmbeddings(array_values($inputs), $settings);

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generatedVectors[$position];
                }
            }

            foreach ($batch as $index => $record) {
                $record['row'][$settings['attribute']] = $vectors[$index];
                $rows[] = $record['row'];
            }
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function delete(iterable $entities): void
    {
        $list = collection($entities)->toList();

        if ($list === []) {
            return;
        }

        $keys = [];
        foreach ($list as $entity) {
            $keys[] = $this->exploratorKey($entity);
        }

        $table = $this->getTableLocator()->get($list[0]->getSource());

        $this->turbopuffer
            ->namespace(method_exists($table, 'indexableAs') ? $table->indexableAs() : $table->getTable())
            ->write(['deletes' => $keys]);
    }

    /**
     * @inheritDoc
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch(
            $builder,
            min($builder->limit ?? 10000, 10000),
        );
    }

    /**
     * @inheritDoc
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $maximum = min($builder->limit ?? 10000, 10000);
        $window = $page * $perPage;

        if ($window > 10000) {
            throw new ExploratorException('Turbopuffer search results may not be paginated beyond 10,000 records.');
        }

        $results = $this->performSearch($builder, min($window, $maximum));

        $results['rows'] = array_slice(
            $results['rows'] ?? [],
            ($page - 1) * $perPage,
            $perPage,
        );

        $nativeFilters = $builder->options['filters'] ?? null;

        $filters = $this->combineFilters($nativeFilters, $this->filters($builder));

        $count = $this->namespace($builder)->query(array_filter([
            'aggregate_by' => ['count' => ['Count']],
            'filters' => $filters,
            'consistency' => $builder->options['consistency'] ?? null,
        ], fn($value): bool => !is_null($value)));

        $results['total'] = min((int)($count['aggregations']['count'] ?? 0), $maximum);

        return $results;
    }

    /**
     * Perform a search against Turbopuffer.
     *
     * @return array<string, mixed>
     */
    protected function performSearch(Builder $builder, int $limit): array
    {
        $namespace = $this->namespace($builder);

        $parameters = $this->buildSearchParameters($builder, $limit);

        $results = $builder->callback instanceof Closure
            ? ($builder->callback)($namespace, $builder->query, $parameters)
            : $namespace->query($parameters);

        if (!is_array($results)) {
            throw new ExploratorException('Turbopuffer returned an unexpected response.');
        }

        if (!is_null($builder->hybridSearch)) {
            $results['rows'] = array_slice($results['results'][0]['rows'] ?? [], 0, $limit);
        }

        $results['total'] = count($results['rows'] ?? []);

        return $results;
    }

    /**
     * Build Turbopuffer search parameters for the query.
     *
     * @return array<string, mixed>
     */
    public function buildSearchParameters(Builder $builder, int $limit): array
    {
        if (isset($builder->options['queries'])) {
            throw new ExploratorException('Turbopuffer multi-query searches are not supported by this Explorator engine.');
        }

        if (!is_null($builder->hybridSearch)) {
            return $this->buildHybridSearchParameters($builder, $limit);
        }

        $parameters = $builder->options;
        $nativeFilters = $parameters['filters'] ?? null;
        $exploratorFilters = $this->filters($builder);

        unset($parameters['filters']);

        if ($builder->semanticSearch && isset($parameters['rank_by'])) {
            throw new ExploratorException('Turbopuffer semantic searches cannot be combined with a custom ranking expression.');
        }

        if (!isset($parameters['rank_by'])) {
            $parameters['rank_by'] = $this->rankBy($builder);
        } elseif ($builder->orders !== []) {
            throw new ExploratorException('Turbopuffer order clauses cannot be combined with a custom ranking expression.');
        }

        $filters = $this->combineFilters($nativeFilters, $exploratorFilters);
        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        $parameters = $this->ensureIdIsReturned($parameters);
        $parameters['limit'] = min(max(1, $limit), 10000);

        return $parameters;
    }

    /**
     * Build a hybrid full-text and semantic query.
     *
     * @return array<string, mixed>
     */
    protected function buildHybridSearchParameters(Builder $builder, int $limit): array
    {
        if ($builder->orders !== []) {
            throw new ExploratorException('Turbopuffer order clauses cannot be combined with hybrid search.');
        }

        foreach (['rank_by', 'rerank_by'] as $option) {
            if (isset($builder->options[$option])) {
                throw new ExploratorException("Turbopuffer hybrid searches cannot be combined with a custom [{$option}] option.");
            }
        }

        $parameters = $builder->options;
        $nativeFilters = $parameters['filters'] ?? null;
        $rootParameters = array_intersect_key($parameters, array_flip(['consistency', 'vector_encoding']));

        unset($parameters['consistency'], $parameters['filters'], $parameters['vector_encoding']);

        $filters = $this->combineFilters($nativeFilters, $this->filters($builder));
        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        $parameters = $this->ensureIdIsReturned($parameters);
        $parameters['limit'] = min(max(1, $limit), 10000);

        return array_merge($rootParameters, [
            'queries' => [
                array_merge($parameters, ['rank_by' => $this->fullTextRankBy($builder)]),
                array_merge($parameters, ['rank_by' => $this->semanticRankBy($builder)]),
            ],
            'rerank_by' => ['RRF', [
                'weights' => [
                    $builder->hybridSearch['text_weight'],
                    $builder->hybridSearch['semantic_weight'],
                ],
            ]],
        ]);
    }

    /**
     * Build the ranking expression for the query.
     *
     * @return array<int, mixed>
     */
    protected function rankBy(Builder $builder): array
    {
        if ($builder->semanticSearch) {
            if ($builder->orders !== []) {
                throw new ExploratorException('Turbopuffer order clauses cannot be combined with semantic search.');
            }

            return $this->semanticRankBy($builder);
        }

        if ($builder->query === '' || $builder->query === '*') {
            if (count($builder->orders) > 1) {
                throw new ExploratorException('Turbopuffer supports one order clause per search.');
            }

            $order = $builder->orders[0] ?? ['column' => 'id', 'direction' => 'asc'];

            return [$this->field($builder, $order['column']), $order['direction']];
        }

        if ($builder->orders !== []) {
            throw new ExploratorException('Turbopuffer order clauses cannot be combined with full-text search.');
        }

        return $this->fullTextRankBy($builder);
    }

    /**
     * Build the full-text ranking expression for a query.
     *
     * @return array<int, mixed>
     */
    protected function fullTextRankBy(Builder $builder): array
    {
        $attributes = $this->modelSettings($builder->table)['searchable-attributes'] ?? [];

        if (empty($attributes)) {
            throw new ExploratorException('No Turbopuffer searchable attributes have been configured for [' . $builder->table::class . '].');
        }

        $expressions = [];

        foreach ($attributes as $key => $value) {
            [$attribute, $weight] = is_int($key) ? [$value, 1] : [$key, $value];

            if (!is_string($attribute) || !is_numeric($weight) || $weight < 0) {
                throw new ExploratorException('Turbopuffer searchable attributes must contain attribute names with non-negative numeric weights.');
            }

            $expression = [$attribute, 'BM25', $builder->query];

            $expressions[] = (float)$weight === 1.0
                ? $expression
                : ['Product', $weight, $expression];
        }

        return count($expressions) === 1
            ? $expressions[0]
            : ['Sum', $expressions];
    }

    /**
     * Build the semantic ranking expression for a query.
     *
     * @return array<int, mixed>
     */
    protected function semanticRankBy(Builder $builder): array
    {
        $settings = $this->embeddingSettings($builder->table);

        return [
            $settings['attribute'],
            'ANN',
            $this->usesNativeEmbeddings($settings)
                ? ['Embed', $builder->query]
                : $this->generateEmbeddings([$builder->query], $settings)[0],
        ];
    }

    /**
     * Build filters for the query.
     *
     * @return array<int, mixed>|null
     */
    protected function filters(Builder $builder): ?array
    {
        $filters = [];

        $operators = [
            '=' => 'Eq',
            '!=' => 'NotEq',
            '<' => 'Lt',
            '<=' => 'Lte',
            '>' => 'Gt',
            '>=' => 'Gte',
        ];

        foreach ($builder->wheres as $where) {
            if (!isset($operators[$where['operator']])) {
                throw new ExploratorException("The [{$where['operator']}] operator is not supported by the Turbopuffer engine.");
            }

            $filters[] = [
                $this->field($builder, $where['field']),
                $operators[$where['operator']],
                $this->filterValue($where['value']),
            ];
        }

        foreach ($builder->whereIns as $field => $values) {
            $filters[] = [$this->field($builder, $field), 'In', array_map($this->filterValue(...), $values)];
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $filters[] = [$this->field($builder, $field), 'NotIn', array_map($this->filterValue(...), $values)];
        }

        return match (count($filters)) {
            0 => null,
            1 => $filters[0],
            default => ['And', $filters],
        };
    }

    /**
     * Combine native and Explorator filters.
     *
     * @param array<int, mixed>|null $nativeFilters
     * @param array<int, mixed>|null $exploratorFilters
     * @return array<int, mixed>|null
     */
    protected function combineFilters(?array $nativeFilters, ?array $exploratorFilters): ?array
    {
        if ($nativeFilters === null) {
            return $exploratorFilters;
        }

        if ($exploratorFilters === null) {
            return $nativeFilters;
        }

        return ['And', [$nativeFilters, $exploratorFilters]];
    }

    /**
     * Normalize a filter value.
     *
     * @param mixed $value Value
     * @return mixed
     */
    protected function filterValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * Ensure the Explorator key is returned for entity hydration.
     *
     * @param array<string, mixed> $parameters Parameters
     * @return array<string, mixed>
     */
    protected function ensureIdIsReturned(array $parameters): array
    {
        if (isset($parameters['include_attributes']) && is_array($parameters['include_attributes'])) {
            $parameters['include_attributes'] = array_values(array_unique([
                ...$parameters['include_attributes'],
                'id',
            ]));
        }

        if (isset($parameters['exclude_attributes']) && is_array($parameters['exclude_attributes'])) {
            $parameters['exclude_attributes'] = array_values(array_diff($parameters['exclude_attributes'], ['id']));
        }

        return $parameters;
    }

    /**
     * Resolve a Explorator field name to a Turbopuffer field name.
     */
    protected function field(Builder $builder, string $field): string
    {
        $keyName = method_exists($builder->table, 'getExploratorKeyName')
            ? $builder->table->getExploratorKeyName()
            : 'id';

        return $field === $keyName ? 'id' : $field;
    }

    /**
     * @inheritDoc
     */
    public function mapIds(mixed $results): CollectionInterface
    {
        $rows = $results['rows'] ?? [];
        if ($rows === []) {
            return collection([]);
        }

        return collection($rows)->extract('id');
    }

    /**
     * @inheritDoc
     */
    public function map(Builder $builder, mixed $results): ResultSetInterface
    {
        $rows = $results['rows'] ?? [];
        if ($rows === []) {
            return new ResultSet([]);
        }

        $keyName = 'id';
        $objectIds = collection($rows)->extract($keyName)->toList();
        $objectIdPositions = array_flip($objectIds);

        if (method_exists($builder->table, 'getExploratorModelsByIds')) {
            $models = $builder->table->getExploratorModelsByIds($builder, $objectIds)
                ->filter(function (EntityInterface $entity) use ($objectIds, $keyName): bool {
                    $key = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);

                    return in_array($key, $objectIds, true);
                })
                ->map(function (EntityInterface $entity) use ($rows, $objectIdPositions, $keyName): EntityInterface {
                    $key = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);
                    $row = $rows[$objectIdPositions[$key]] ?? [];

                    foreach ($row as $metaKey => $value) {
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

        $entities = [];
        foreach ($rows as $position => $row) {
            $entity = $builder->table->newEntity([], ['guard' => false]);
            $entity->set('id', $row['id'] ?? null);

            if (method_exists($entity, 'withExploratorMetadata')) {
                foreach ($row as $metaKey => $value) {
                    if (str_starts_with((string)$metaKey, '_')) {
                        $entity->withExploratorMetadata((string)$metaKey, $value);
                    }
                }
            }

            $entities[$position] = $entity;
        }

        return new ResultSet($entities);
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
        return (int)($results['total'] ?? count($results['rows'] ?? []));
    }

    /**
     * @inheritDoc
     */
    public function flush(mixed $table): void
    {
        $name = $table instanceof Table
            ? (method_exists($table, 'indexableAs') ? $table->indexableAs() : $table->getTable())
            : (string)$table;

        $this->deleteIndex($name);
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        throw new ExploratorException('Turbopuffer namespaces are created automatically upon adding documents.');
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $name): mixed
    {
        return $this->turbopuffer->namespace($name)->delete();
    }

    /**
     * Get the configured settings for a table.
     *
     * @param \Cake\ORM\Table $table Table
     * @return array<string, mixed>
     */
    protected function modelSettings(Table $table): array
    {
        return $this->config['model-settings'][$table::class] ?? [];
    }

    /**
     * Get the validated embedding settings for a table.
     *
     * @param \Cake\ORM\Table $table Table
     * @return array<string, mixed>
     */
    protected function embeddingSettings(Table $table): array
    {
        $modelSettings = $this->modelSettings($table);

        $settings = $modelSettings['embedding'] ?? null;

        if (!is_array($settings)) {
            throw new ExploratorException('No Turbopuffer embedding settings have been configured for [' . $table::class . '].');
        }

        $settings = $this->validateEmbeddingSettings($settings);

        if ($this->usesNativeEmbeddings($settings)) {
            $schema = $modelSettings['schema'][$settings['attribute']] ?? null;

            if (!is_array($schema) || ($schema['type'] ?? null) !== 'string') {
                throw new ExploratorException("Turbopuffer native embeddings require a string schema configuration for the [{$settings['attribute']}] attribute.");
            }

            $settings['generated_attribute'] = $this->validateNativeEmbeddingSchema($settings['attribute'], $schema['embed'] ?? null);
        }

        return $settings;
    }

    /**
     * Validate embedding configuration shared by indexing and querying.
     *
     * @param array<string, mixed> $settings Settings
     * @return array<string, mixed>
     */
    protected function validateEmbeddingSettings(array $settings): array
    {
        if (!isset($settings['attribute']) || !is_string($settings['attribute']) || trim($settings['attribute']) === '') {
            throw new ExploratorException('Turbopuffer embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'crustum-ai';

        if (!in_array($driver, ['crustum-ai', 'turbopuffer'], true)) {
            throw new ExploratorException("The [{$driver}] Turbopuffer embedding driver is not supported.");
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
            throw new ExploratorException('Turbopuffer embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int)$settings['dimensions'];

        return $settings;
    }

    /**
     * Determine if Turbopuffer should generate embeddings natively.
     *
     * @param array<string, mixed> $settings Settings
     */
    protected function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'turbopuffer';
    }

    /**
     * Validate a native embedding schema and return its vector attribute.
     */
    protected function validateNativeEmbeddingSchema(string $attribute, mixed $embed): string
    {
        if (is_string($embed) && trim($embed) !== '') {
            return 'embed_' . $attribute;
        }

        if (!is_array($embed) || !isset($embed['model']) || !is_string($embed['model']) || trim($embed['model']) === '') {
            throw new ExploratorException("Turbopuffer native embeddings require a valid [embed] schema configuration for the [{$attribute}] attribute.");
        }

        if (isset($embed['dimensions']) && (filter_var($embed['dimensions'], FILTER_VALIDATE_INT) === false || $embed['dimensions'] < 1)) {
            throw new ExploratorException('Turbopuffer native embedding [dimensions] must be a positive integer.');
        }

        if (isset($embed['attribute']) && (!is_string($embed['attribute']) || trim($embed['attribute']) === '')) {
            throw new ExploratorException('Turbopuffer native embedding [attribute] must be a non-empty string.');
        }

        return $embed['attribute'] ?? 'embed_' . $attribute;
    }

    /**
     * Generate and validate embeddings using the Crustum AI SDK.
     *
     * @param list<string> $inputs Inputs
     * @param array<string, mixed> $settings Settings
     * @return list<array<int, float>>
     */
    protected function generateEmbeddings(array $inputs, array $settings): array
    {
        if (!class_exists(Embeddings::class)) {
            throw new ExploratorException('Semantic search requires the crustum/cakephp-ai package. Please install it.');
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
     * Get the Turbopuffer namespace for a search.
     */
    protected function namespace(Builder $builder): TurbopufferNamespace
    {
        $name = $builder->index
            ?: (method_exists($builder->table, 'searchableAs') ? $builder->table->searchableAs() : $builder->table->getTable());

        return $this->turbopuffer->namespace($name);
    }

    /**
     * Determine if the entity uses soft deletes.
     */
    protected function usesSoftDelete(EntityInterface $entity): bool
    {
        $table = $this->getTableLocator()->get($entity->getSource());

        return $table->hasBehavior('SoftDelete');
    }

    /**
     * Get the explorator key for an entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return string|int
     */
    protected function exploratorKey(EntityInterface $entity): int|string
    {
        return method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get('id');
    }
}
