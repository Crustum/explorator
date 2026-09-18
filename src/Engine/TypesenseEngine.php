<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engine;

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
use Crustum\Explorator\Exception\ExploratorException;
use Exception;
use stdClass;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Documents;
use Typesense\Exceptions\ObjectAlreadyExists;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\ObjectUnprocessable;
use Typesense\Exceptions\RequestMalformed;
use Typesense\Exceptions\RequestUnauthorized;
use Typesense\Exceptions\ServiceUnavailable;
use Typesense\Exceptions\TypesenseClientError;

/**
 * Typesense Explorator engine (Cake rewrite).
 *
 * Typesense HTTP import/delete responses complete when the write is applied, so
 * Explorator.wait_for_tasks is a no-op here (kept for driver parity).
 */
class TypesenseEngine extends Engine implements SupportsSemanticSearch
{
    use LocatorAwareTrait;

    /**
     * @var int
     */
    private int $maxPerPage = 250;

    /**
     * @param \Typesense\Client $typesense Client
     * @param int $maxTotalResults Max total results
     * @param bool $softDelete Soft delete
     * @param array<string, mixed> $config Typesense engine configuration
     */
    public function __construct(
        protected TypesenseClient $typesense,
        protected int $maxTotalResults = 1000,
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
        $collection = $this->getOrCreateCollectionFromModel($first);

        if ($this->usesSoftDelete($first) && $this->softDelete) {
            foreach ($list as $entity) {
                if (method_exists($entity, 'pushSoftDeleteMetadata')) {
                    $entity->pushSoftDeleteMetadata();
                }
            }
        }

        $objects = [];
        $records = [];
        foreach ($list as $entity) {
            $searchableData = method_exists($entity, 'toSearchableArray')
                ? $entity->toSearchableArray()
                : $entity->toArray();
            if ($searchableData === []) {
                continue;
            }

            $metadata = method_exists($entity, 'exploratorMetadata') ? $entity->exploratorMetadata() : [];
            $records[] = [
                'entity' => $entity,
                'object' => array_merge($searchableData, $metadata),
            ];
        }

        if ($records !== []) {
            $table = $this->getTableLocator()->get($first->getSource());

            $settings = $this->modelSettings($table);

            $embeddingSettings = isset($settings['embedding'])
                ? $this->embeddingSettings($table)
                : null;

            $objects = $embeddingSettings && !$this->usesNativeEmbeddings($embeddingSettings)
                ? $this->addEmbeddingsToRecords($records, $embeddingSettings)
                : array_column($records, 'object');

            $this->importDocuments($collection, $objects);
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
                $generatedVectors = $this->generateEmbeddings(array_values($inputs), $settings);

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generatedVectors[$position];
                }
            }

            foreach ($batch as $index => $record) {
                $object = $record['object'];

                $object[$settings['attribute']] = $vectors[$index];

                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * Import documents into a Typesense collection.
     *
     * @param \Typesense\Collection $collectionIndex Collection
     * @param list<array<string, mixed>> $documents Documents
     * @param string|null $action Import action
     * @return \Cake\Collection\CollectionInterface<\stdClass>
     */
    protected function importDocuments(
        TypesenseCollection $collectionIndex,
        array $documents,
        ?string $action = null,
    ): CollectionInterface {
        $action ??= (string)Configure::read('Explorator.typesense.import_action', 'upsert');

        $importedDocuments = $collectionIndex->getDocuments()->import($documents, ['action' => $action]);

        $results = [];
        foreach ($importedDocuments as $importedDocument) {
            if (!$importedDocument['success']) {
                throw new TypesenseClientError("Error importing document: {$importedDocument['error']}");
            }

            $results[] = $this->createImportSortingDataObject($importedDocument);
        }

        return collection($results);
    }

    /**
     * Create an import sorting data object for a given document.
     *
     * @param array<string, mixed> $document Imported document row
     * @return \stdClass
     */
    protected function createImportSortingDataObject(array $document): stdClass
    {
        $data = new stdClass();
        $data->code = $document['code'] ?? 0;
        $data->success = $document['success'];
        $data->error = $document['error'] ?? null;
        $data->document = json_decode($document['document'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function delete(iterable $entities): void
    {
        foreach (collection($entities)->toList() as $entity) {
            $this->deleteDocument(
                $this->getOrCreateCollectionFromModel($entity),
                method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get('id'),
            );
        }
    }

    /**
     * Delete a document from the index.
     *
     * @param \Typesense\Collection $collectionIndex Collection
     * @param mixed $modelId Document id
     * @return array<string, mixed>
     */
    protected function deleteDocument(TypesenseCollection $collectionIndex, mixed $modelId): array
    {
        $document = $collectionIndex->getDocuments()[(string)$modelId];

        try {
            $document->retrieve();

            return $document->delete();
        } catch (Exception) {
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function search(Builder $builder): mixed
    {
        if (($builder->limit ?? 0) >= $this->maxPerPage) {
            return $this->performPaginatedSearch($builder);
        }

        return $this->performSearch(
            $builder,
            $this->buildSearchParameters($builder, 1, $builder->limit ?? $this->maxPerPage),
        );
    }

    /**
     * @inheritDoc
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $maxInt = 4294967295;

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        if ($page * $perPage > $maxInt) {
            $page = (int)floor($maxInt / $perPage);
        }

        return $this->performSearch(
            $builder,
            $this->buildSearchParameters($builder, $page, $perPage),
        );
    }

    /**
     * Perform the given search on the engine.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @param array<string, mixed> $options Search options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $documents = $this->getOrCreateCollectionFromModel(
            $builder->table,
            $builder->index,
            false,
        )->getDocuments();

        if ($builder->callback instanceof Closure) {
            return ($builder->callback)($documents, $builder->query, $options);
        }

        try {
            return $this->executeSearch($builder, $documents, $options);
        } catch (ObjectNotFound) {
            $this->getOrCreateCollectionFromModel($builder->table, $builder->index, true);

            return $this->executeSearch($builder, $documents, $options);
        }
    }

    /**
     * Execute the given search using the appropriate Typesense endpoint.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @param \Typesense\Documents $documents Documents
     * @param array<string, mixed> $options Search options
     * @return mixed
     */
    protected function executeSearch(Builder $builder, Documents $documents, array $options): mixed
    {
        if (!isset($options['vector_query'])) {
            return $documents->search($options);
        }

        $results = $this->typesense->getMultiSearch()->perform([
            'searches' => [
                array_merge($options, [
                    'collection' => $builder->index ?? $this->indexName($builder->table),
                ]),
            ],
        ]);

        $result = $results['results'][0] ?? [];

        if (isset($result['error'])) {
            throw $this->marshalMultiSearchException($result);
        }

        return $result;
    }

    /**
     * Convert a multi-search error result into a Typesense exception.
     *
     * @param array<string, mixed> $result Result
     * @return \Typesense\Exceptions\TypesenseClientError
     */
    protected function marshalMultiSearchException(array $result): TypesenseClientError
    {
        $exception = match ((int)($result['code'] ?? 500)) {
            400 => new RequestMalformed(),
            401 => new RequestUnauthorized(),
            404 => new ObjectNotFound(),
            409 => new ObjectAlreadyExists(),
            422 => new ObjectUnprocessable(),
            503 => new ServiceUnavailable(),
            default => new TypesenseClientError(),
        };

        return $exception->setMessage($result['error']);
    }

    /**
     * Perform a paginated search when the limit exceeds Typesense page size.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @return array<string, mixed>
     */
    protected function performPaginatedSearch(Builder $builder): array
    {
        $page = 1;
        $limit = min($builder->limit ?? $this->maxPerPage, $this->maxPerPage, $this->maxTotalResults);
        $remainingResults = min($builder->limit ?? $this->maxTotalResults, $this->maxTotalResults);
        $totalFound = 0;

        $results = collection([]);

        while ($remainingResults > 0) {
            $searchResults = $this->performSearch(
                $builder,
                $this->buildSearchParameters($builder, $page, $limit),
            );

            $results = $results->append($searchResults['hits'] ?? []);

            if ($page === 1) {
                $totalFound = (int)($searchResults['found'] ?? 0);
            }

            $remainingResults -= $limit;
            $page++;

            if (count($searchResults['hits'] ?? []) < $limit) {
                break;
            }
        }

        return [
            'hits' => $results->toList(),
            'found' => $results->count(),
            'out_of' => $totalFound,
            'page' => 1,
            'request_params' => $this->buildSearchParameters($builder, 1, $builder->limit ?? $this->maxPerPage),
        ];
    }

    /**
     * Build the search parameters for a given Explorator query builder.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @param int $page Page number
     * @param int|null $perPage Page size
     * @return array<string, mixed>
     */
    public function buildSearchParameters(Builder $builder, int $page, ?int $perPage): array
    {
        $tableClass = $builder->table::class;
        $modelSettings = (array)Configure::read('Explorator.typesense.model-settings.' . $tableClass, []);
        $searchParameters = (array)($modelSettings['search-parameters'] ?? []);

        $parameters = [
            'q' => $builder->query,
            'query_by' => $searchParameters['query_by'] ?? '',
            'filter_by' => $this->filters($builder),
            'per_page' => $perPage,
            'page' => $page,
            'highlight_start_tag' => '<mark>',
            'highlight_end_tag' => '</mark>',
            'snippet_threshold' => 30,
            'exhaustive_search' => false,
            'use_cache' => false,
            'cache_ttl' => 60,
            'prioritize_exact_match' => true,
            'enable_overrides' => true,
            'highlight_affix_num_tokens' => 4,
            'prefix' => $searchParameters['prefix'] ?? true,
        ];

        if (method_exists($builder->table, 'typesenseSearchParameters')) {
            $parameters = array_merge($parameters, $builder->table->typesenseSearchParameters());
        }

        if ($builder->options !== []) {
            $parameters = array_merge($parameters, $builder->options);
        }

        if ($builder->orders !== []) {
            if (!empty($parameters['sort_by'])) {
                $parameters['sort_by'] .= ',';
            } else {
                $parameters['sort_by'] = '';
            }

            $parameters['sort_by'] .= $this->parseOrderBy($builder->orders);
        }

        return $this->applySemanticSearchParameters($builder, $parameters);
    }

    /**
     * Apply semantic and hybrid search parameters to the search parameters.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @param array<string, mixed> $parameters Parameters
     * @return array<string, mixed>
     */
    protected function applySemanticSearchParameters(Builder $builder, array $parameters): array
    {
        if (!$builder->semanticSearch && $builder->hybridSearch === null) {
            return $parameters;
        }

        if (array_key_exists('vector_query', $builder->options)) {
            throw new ExploratorException('Typesense semantic and hybrid searches cannot be combined with a custom [vector_query] option.');
        }

        $settings = $this->embeddingSettings($builder->table);

        unset($parameters['vector']);

        if ($builder->semanticSearch) {
            $parameters = $this->usesNativeEmbeddings($settings)
                ? $this->applyNativeSemanticQueryBy($parameters, $settings['attribute'])
                : array_merge($parameters, ['q' => '*']);
        } else {
            $parameters = $this->applyHybridQueryBy($parameters, $settings);
        }

        $vectorQuery = $this->buildVectorQueryParameter($builder, $settings);
        if ($vectorQuery !== null) {
            $parameters['vector_query'] = $vectorQuery;
        }

        $parameters['exclude_fields'] = $this->appendField($parameters['exclude_fields'] ?? '', $settings['attribute']);

        return $parameters;
    }

    /**
     * Target the embedding field for a semantic search using native embeddings.
     *
     * @param array<string, mixed> $parameters Parameters
     * @return array<string, mixed>
     */
    protected function applyNativeSemanticQueryBy(array $parameters, string $attribute): array
    {
        $parameters['query_by'] = $attribute;

        $parameters['prefix'] = false;

        unset($parameters['query_by_weights']);

        foreach (['num_typos', 'infix'] as $parameter) {
            if (isset($parameters[$parameter]) && str_contains((string)$parameters[$parameter], ',')) {
                unset($parameters[$parameter]);
            }
        }

        return $parameters;
    }

    /**
     * Prepare the "query_by" fields and their per-field parameters for a hybrid search.
     *
     * @param array<string, mixed> $parameters Parameters
     * @param array<string, mixed> $settings Embedding settings
     * @return array<string, mixed>
     */
    protected function applyHybridQueryBy(array $parameters, array $settings): array
    {
        $fields = array_filter(array_map(trim(...), explode(',', (string)($parameters['query_by'] ?? ''))));

        if (array_diff($fields, [$settings['attribute']]) === []) {
            throw new ExploratorException('Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');
        }

        if (!$this->usesNativeEmbeddings($settings) || in_array($settings['attribute'], $fields, true)) {
            return $parameters;
        }

        $parameters['query_by'] = implode(',', [...$fields, $settings['attribute']]);

        if (isset($parameters['query_by_weights']) && $parameters['query_by_weights'] !== '') {
            $parameters['query_by_weights'] .= ',0';
        }

        foreach (['num_typos' => '0', 'prefix' => 'false', 'infix' => 'off'] as $parameter => $placeholder) {
            if (isset($parameters[$parameter]) && str_contains((string)$parameters[$parameter], ',')) {
                $parameters[$parameter] .= ',' . $placeholder;
            }
        }

        if (($parameters['prefix'] ?? null) === true || ($parameters['prefix'] ?? null) === 'true') {
            $parameters['prefix'] = implode(',', [...array_fill(0, count($fields), 'true'), 'false']);
        }

        return $parameters;
    }

    /**
     * Build the "vector_query" parameter for the search.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @param array<string, mixed> $settings Embedding settings
     * @return string|null
     */
    protected function buildVectorQueryParameter(Builder $builder, array $settings): ?string
    {
        if ($this->usesNativeEmbeddings($settings)) {
            $vector = [];
        } else {
            $vector = $builder->options['vector'] ??
                $this->generateEmbeddings([$builder->query], $settings)[0];

            if (!is_array($vector) || $vector === []) {
                throw new ExploratorException('The Typesense query [vector] must be a non-empty embedding array.');
            }
        }

        $options = [];

        if ($builder->hybridSearch !== null) {
            $options[] = 'alpha: ' . ($builder->hybridSearch['semantic_weight'] / array_sum($builder->hybridSearch));
        }

        if ($builder->minimumSimilarity !== null) {
            $options[] = 'distance_threshold: ' . $this->distanceThreshold($builder);
        }

        if ($vector === [] && $options === []) {
            return null;
        }

        return sprintf(
            '%s:([%s]%s)',
            $settings['attribute'],
            implode(', ', $vector),
            $options === [] ? '' : ', ' . implode(', ', $options),
        );
    }

    /**
     * Get the maximum vector distance for the search's minimum similarity.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @return float
     */
    protected function distanceThreshold(Builder $builder): float
    {
        $similarity = $builder->minimumSimilarity;

        if (!is_numeric($similarity) || $similarity < 0 || $similarity > 1) {
            throw new ExploratorException('The minimum similarity must be between 0 and 1.');
        }

        return 1 - $similarity;
    }

    /**
     * Append a field to a comma-separated field list if not already present.
     *
     * @param string $fields Fields
     * @param string $field Field
     * @return string
     */
    protected function appendField(string $fields, string $field): string
    {
        $list = array_filter(array_map(trim(...), explode(',', $fields)));

        if (!in_array($field, $list, true)) {
            $list[] = $field;
        }

        return implode(',', $list);
    }

    /**
     * Prepare the filters for a given search query.
     *
     * @param \Crustum\Explorator\Builder $builder Builder
     * @return string
     */
    protected function filters(Builder $builder): string
    {
        /** @var list<string> $whereFilter */
        $whereFilter = collection($builder->wheres)
            ->map(fn(array $where): string => $this->parseWhereFilter(
                $this->parseFilterValue($where['value']),
                $where['field'],
                $where['operator'],
            ))
            ->toList();

        /** @var list<string> $whereInFilter */
        $whereInFilter = collection($builder->whereIns)
            ->map(fn(array $value, string $key): string => $this->parseWhereInFilter(
                $this->parseFilterValue($value),
                $key,
            ))
            ->toList();

        /** @var list<string> $whereNotInFilter */
        $whereNotInFilter = collection($builder->whereNotIns)
            ->map(fn(array $value, string $key): string => $this->parseWhereNotInFilter(
                $this->parseFilterValue($value),
                $key,
            ))
            ->toList();

        /** @var list<string> $parts */
        $parts = collection([
            implode(' && ', $whereFilter),
            implode(' && ', $whereInFilter),
            implode(' && ', $whereNotInFilter),
        ])->filter(fn(string $filter): bool => $filter !== '')->toList();

        return implode(' && ', $parts);
    }

    /**
     * Parse the given filter value.
     *
     * @param array<string|bool|int|float>|string|float|int|bool $value Value
     * @return array<string|bool|int|float>|string|float|int|bool
     */
    protected function parseFilterValue(array|bool|float|int|string $value): array|bool|float|int|string
    {
        if (is_array($value)) {
            return array_map($this->parseFilterValue(...), $value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }

    /**
     * Create a "where" filter string.
     *
     * @param array<int, string>|string|float|int|bool $value Value
     * @param string $key Field
     * @param string $operator Operator
     * @return string
     */
    protected function parseWhereFilter(array|bool|float|int|string $value, string $key, string $operator = '='): string
    {
        if (is_array($value)) {
            return sprintf('%s:%s', $key, implode('', $value));
        }

        $operator = match ($operator) {
            '=' => ':=',
            '!=' => ':!=',
            '<' => ':<',
            '>' => ':>',
            '<=' => ':<=',
            '>=' => ':>=',
            default => ':=',
        };

        return sprintf('%s%s%s', $key, $operator, $value);
    }

    /**
     * Create a "where in" filter string.
     *
     * @param array<int, mixed> $value Values
     * @param string $key Field
     * @return string
     */
    protected function parseWhereInFilter(array $value, string $key): string
    {
        return sprintf('%s:=[%s]', $key, implode(', ', $value));
    }

    /**
     * Create a "where not in" filter string.
     *
     * @param array<int, mixed> $value Values
     * @param string $key Field
     * @return string
     */
    protected function parseWhereNotInFilter(array $value, string $key): string
    {
        return sprintf('%s:!=[%s]', $key, implode(', ', $value));
    }

    /**
     * Parse the order by fields for the query.
     *
     * @param list<array{column: string, direction: string}> $orders Orders
     * @return string
     */
    protected function parseOrderBy(array $orders): string
    {
        $orderBy = [];
        foreach ($orders as $order) {
            $orderBy[] = $order['column'] . ':' . $order['direction'];
        }

        return implode(',', $orderBy);
    }

    /**
     * @inheritDoc
     */
    public function mapIds(mixed $results): CollectionInterface
    {
        return collection($results['hits'] ?? [])->extract('document.id');
    }

    /**
     * @inheritDoc
     */
    public function map(Builder $builder, mixed $results): ResultSetInterface
    {
        if ($this->getTotalCount($results) === 0) {
            return new ResultSet([]);
        }

        $hits = empty($results['grouped_hits'])
            ? $results['hits'] ?? []
            : $results['grouped_hits'];

        $pluck = empty($results['grouped_hits'])
            ? 'document.id'
            : 'hits.0.document.id';

        $objectIds = collection($hits)->extract($pluck)->toList();
        if ($objectIds === []) {
            return new ResultSet([]);
        }

        $objectIdPositions = array_flip($objectIds);
        $keyName = method_exists($builder->table, 'getExploratorKeyName')
            ? $builder->table->getExploratorKeyName()
            : 'id';

        if (method_exists($builder->table, 'getExploratorModelsByIds')) {
            $models = $builder->table->getExploratorModelsByIds($builder, $objectIds)
                ->filter(function (EntityInterface $entity) use ($objectIds, $keyName): bool {
                    $key = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);

                    return in_array($key, $objectIds, false);
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
        if ((int)($results['found'] ?? 0) === 0) {
            return collection([]);
        }

        return collection($this->map($builder, $results)->toList());
    }

    /**
     * @inheritDoc
     */
    public function getTotalCount(mixed $results): int
    {
        return (int)($results['found'] ?? 0);
    }

    /**
     * @inheritDoc
     */
    public function flush(mixed $table): void
    {
        if (!$table instanceof Table) {
            return;
        }

        $this->getOrCreateCollectionFromModel($table)->delete();
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        throw new Exception('Typesense indexes are created automatically upon adding objects.');
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $name): mixed
    {
        return $this->typesense->getCollections()->{$name}->delete();
    }

    /**
     * Get collection from model or create a new one.
     *
     * @param \Cake\Datasource\EntityInterface|\Cake\ORM\Table $model Entity or table
     * @param string|null $collectionName Collection name override
     * @param bool $indexOperation Whether this is an index write operation
     * @return \Typesense\Collection
     */
    protected function getOrCreateCollectionFromModel(
        EntityInterface|Table $model,
        ?string $collectionName = null,
        bool $indexOperation = true,
    ): TypesenseCollection {
        $table = $this->resolveTable($model);

        if (!$indexOperation) {
            $collectionName ??= $this->indexName($table);
        } else {
            $collectionName = method_exists($table, 'indexableAs')
                ? $table->indexableAs()
                : $this->indexName($table);
        }

        $collection = $this->typesense->getCollections()->{$collectionName};

        if (!$indexOperation) {
            return $collection;
        }

        try {
            $collection->retrieve();
            $collection->setExists(true);

            return $collection;
        } catch (TypesenseClientError) {
        }

        $tableClass = $table::class;
        $schema = (array)Configure::read('Explorator.typesense.model-settings.' . $tableClass . '.collection-schema', []);

        if (method_exists($table, 'typesenseCollectionSchema')) {
            $schema = $table->typesenseCollectionSchema();
        }

        if (!isset($schema['name'])) {
            $schema['name'] = $this->indexName($table);
        }

        try {
            $this->typesense->getCollections()->create($schema);
        } catch (ObjectAlreadyExists) {
        }

        $collection->setExists(true);

        return $collection;
    }

    /**
     * Resolve the table instance for an entity or table argument.
     *
     * @param \Cake\Datasource\EntityInterface|\Cake\ORM\Table $model Entity or table
     * @return \Cake\ORM\Table
     */
    protected function resolveTable(EntityInterface|Table $model): Table
    {
        if ($model instanceof Table) {
            return $model;
        }

        $source = $model->getSource();
        if ($source === '') {
            return new Table(['alias' => 'Default', 'table' => 'default']);
        }

        return $this->getTableLocator()->get($source);
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
     * @param \Cake\ORM\Table $table Table
     * @return string
     */
    protected function indexName(Table $table): string
    {
        return method_exists($table, 'searchableAs') ? $table->searchableAs() : $table->getTable();
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
        $settings = $this->modelSettings($table)['embedding'] ?? null;

        if (!is_array($settings)) {
            throw new ExploratorException('No Typesense embedding settings have been configured for [' . $table::class . '].');
        }

        if (!isset($settings['attribute']) || !is_string($settings['attribute']) || trim($settings['attribute']) === '') {
            throw new ExploratorException('Typesense embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'crustum-ai';

        if (!in_array($driver, ['crustum-ai', 'typesense'], true)) {
            throw new ExploratorException("The [{$driver}] Typesense embedding driver is not supported.");
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
            throw new ExploratorException('Typesense embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int)$settings['dimensions'];

        return $settings;
    }

    /**
     * Determine if Typesense should generate embeddings natively.
     *
     * @param array<string, mixed> $settings Embedding settings
     * @return bool
     */
    protected function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'typesense';
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
     * Dynamically proxy missing methods to the Typesense client instance.
     *
     * @param string $method Method
     * @param array<int, mixed> $parameters Parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->typesense->{$method}(...$parameters);
    }
}
