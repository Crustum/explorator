<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engine\Trait;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\Database\Expression\QueryExpression;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Crustum\Ai\Embeddings;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Exception\ExploratorException;

/**
 * Database semantic / hybrid vector search (pgvector).
 *
 * @method string getExploratorKeyName(\Cake\ORM\Table $table)
 * @method string qualifyColumn(\Crustum\Explorator\Builder $builder, string $column)
 * @method array<string, mixed> searchableArray(\Crustum\Explorator\Builder $builder)
 * @method list<string> getPrefixColumns(\Crustum\Explorator\Builder $builder)
 * @method list<string> getFullTextColumns(\Crustum\Explorator\Builder $builder)
 * @method \Cake\ORM\Query\SelectQuery newExploratorQuery(\Crustum\Explorator\Builder $builder)
 * @method \Cake\ORM\Query\SelectQuery addTextSearchConstraints(\Cake\ORM\Query\SelectQuery $query, \Crustum\Explorator\Builder $builder, array $columns, array $prefixColumns, array $fullTextColumns)
 * @method \Cake\ORM\Query\SelectQuery constrainForSoftDeletes(\Crustum\Explorator\Builder $builder, \Cake\ORM\Query\SelectQuery $query)
 * @method \Cake\ORM\Query\SelectQuery addAdditionalConstraints(\Crustum\Explorator\Builder $builder, \Cake\ORM\Query\SelectQuery $query)
 * @method bool shouldOrderByRelevance(\Crustum\Explorator\Builder $builder)
 * @method \Cake\ORM\Query\SelectQuery orderByRelevance(\Crustum\Explorator\Builder $builder, \Cake\ORM\Query\SelectQuery $query)
 */
trait PerformsDatabaseVectorSearchTrait
{
    /**
     * Update the searchable embeddings for the given entities.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    protected function updateSearchableEmbeddings(iterable $entities): void
    {
        $list = collection($entities)->toList();

        if ($list === []) {
            return;
        }

        $table = $this->getTableLocator()->get($list[0]->getSource());

        if (!$this->supportsVectorSearch($table) || !method_exists($list[0], 'toSearchableEmbedding')) {
            return;
        }

        foreach (array_chunk($list, 100) as $batch) {
            $inputs = [];
            $vectors = [];

            foreach ($batch as $index => $entity) {
                $input = call_user_func([$entity, 'toSearchableEmbedding']);

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
                $generatedVectors = $this->generateEmbeddings(array_values($inputs), $table);

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generatedVectors[$position];
                }
            }

            $keyName = $this->getExploratorKeyName($table);

            foreach ($batch as $index => $entity) {
                $column = $this->embeddingColumn($table);
                $key = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);

                $table->updateAll(
                    [$column => json_encode($vectors[$index], JSON_THROW_ON_ERROR)],
                    [$keyName => $key],
                );

                $entity->set($column, $vectors[$index]);
            }
        }
    }

    /**
     * Get hybrid search results using weighted reciprocal rank fusion.
     *
     * @return \Cake\Collection\CollectionInterface
     */
    protected function hybridSearchModels(Builder $builder, int $limit): CollectionInterface
    {
        if ($builder->orders !== []) {
            throw new ExploratorException('Database order clauses cannot be combined with hybrid search.');
        }

        if ($limit <= 0) {
            return new Collection([]);
        }

        $vector = $this->generateEmbeddings([$builder->query], $builder->table)[0];

        $column = $this->qualifyColumn($builder, $this->embeddingColumn($builder->table));

        $baseQuery = $this->newExploratorQuery($builder);

        $textQuery = $this->constrainSearchQuery($builder, $this->addTextSearchConstraints(
            clone $baseQuery,
            $builder,
            array_keys($this->searchableArray($builder)),
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder),
        )->limit(1000));

        if (!$this->getFullTextColumns($builder)) {
            $textQuery->orderBy([$this->qualifyColumn($builder, $this->getExploratorKeyName($builder->table)) => 'DESC']);
        } elseif ($this->shouldOrderByRelevance($builder)) {
            $this->orderByRelevance($builder, $textQuery);
            $textQuery->orderBy([$this->qualifyColumn($builder, $this->getExploratorKeyName($builder->table)) => 'DESC']);
        }

        $semanticQuery = $this->constrainSearchQuery($builder, (clone $baseQuery)
            ->limit(1000));
        $semanticQuery = $this->applyVectorDistance($semanticQuery, $column, $vector, '<=', $this->minimumSimilarity($builder));
        $semanticQuery = $this->applyVectorOrder($semanticQuery, $column, $vector);
        $semanticQuery->orderBy([$this->qualifyColumn($builder, $this->getExploratorKeyName($builder->table)) => 'DESC']);

        return $this->fuseSearchResults(
            $builder,
            $textQuery->all()->toList(),
            $semanticQuery->all()->toList(),
        )->take($limit);
    }

    /**
     * Fuse text and semantic results using weighted reciprocal rank fusion.
     *
     * @param list<\Cake\Datasource\EntityInterface> $textModels Text results
     * @param list<\Cake\Datasource\EntityInterface> $semanticModels Semantic results
     * @return \Cake\Collection\Collection
     */
    protected function fuseSearchResults(Builder $builder, array $textModels, array $semanticModels): Collection
    {
        [$scores, $models] = [[], []];

        foreach (
            [
            [$textModels, $builder->hybridSearch['text_weight']],
            [$semanticModels, $builder->hybridSearch['semantic_weight']],
            ] as [$rankedModels, $weight]
        ) {
            $seen = [];

            foreach ($rankedModels as $position => $model) {
                $key = (string)(method_exists($model, 'getExploratorKey') ? $model->getExploratorKey() : $model->get('id'));

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $models[$key] = $model;
                $scores[$key] = ($scores[$key] ?? 0) + ($weight / (60 + $position + 1));
            }
        }

        uksort($scores, fn($left, $right): int => $scores[$right] <=> $scores[$left] ?: strcmp((string)$left, (string)$right));

        return collection(array_map(fn($key): EntityInterface => $models[$key], array_keys($scores)));
    }

    /**
     * Build a semantic search query.
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function buildSemanticSearchQuery(Builder $builder): SelectQuery
    {
        $this->ensureSemanticSearchIsSupported($builder);

        if ($builder->orders !== []) {
            throw new ExploratorException('Database order clauses cannot be combined with semantic search.');
        }

        $vector = $this->generateEmbeddings([$builder->query], $builder->table)[0];

        $column = $this->qualifyColumn($builder, $this->embeddingColumn($builder->table));

        $query = $this->newExploratorQuery($builder);
        $query = $this->applyVectorDistance($query, $column, $vector, '<=', $this->minimumSimilarity($builder));
        $query = $this->applyVectorOrder($query, $column, $vector);
        $query->orderBy([$this->qualifyColumn($builder, $this->getExploratorKeyName($builder->table)) => 'DESC']);
        $query->limit($builder->limit ?? 1000);

        return $this->constrainSearchQuery($builder, $query);
    }

    /**
     * Get the minimum similarity for a semantic search.
     */
    protected function minimumSimilarity(Builder $builder): float
    {
        $similarity = $builder->minimumSimilarity ?? 0.6;

        if ($similarity < 0 || $similarity > 1) {
            throw new ExploratorException('The minimum similarity must be between 0 and 1.');
        }

        return $similarity;
    }

    /**
     * Apply non-search constraints to a query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function constrainSearchQuery(Builder $builder, SelectQuery $query): SelectQuery
    {
        return $this->constrainForSoftDeletes($builder, $this->addAdditionalConstraints($builder, $query));
    }

    /**
     * Generate embeddings using the Crustum AI SDK.
     *
     * @param list<string> $inputs Inputs
     * @param \Cake\ORM\Table|null $table Table (for per-table embedding settings)
     * @return list<array<int, float>>
     */
    protected function generateEmbeddings(array $inputs, ?Table $table = null): array
    {
        if (!class_exists(Embeddings::class)) {
            throw new ExploratorException('Semantic search requires the crustum/cakephp-ai package. Please install it.');
        }

        $settings = $table instanceof Table ? $this->databaseEmbeddingSettings($table) : [];

        $response = Embeddings::for($inputs)
            ->dimensions($settings['dimensions'] ?? 1536)
            ->cache()
            ->generate($settings['provider'] ?? null, $settings['model'] ?? null);

        $embeddings = $response->embeddings;

        if (count($embeddings) !== count($inputs)) {
            throw new ExploratorException('The embeddings provider returned an unexpected number of embeddings.');
        }

        return $embeddings;
    }

    /**
     * Get the database embedding column for a table.
     *
     * @param \Cake\ORM\Table $table Table
     */
    protected function embeddingColumn(Table $table): string
    {
        $settings = $this->databaseEmbeddingSettings($table);

        $column = $settings['attribute'] ?? (method_exists($table, 'searchableEmbeddingColumn')
            ? $table->searchableEmbeddingColumn()
            : 'embedding');

        if (!is_string($column) || trim($column) === '') {
            throw new ExploratorException('The embedding column must be a non-empty string.');
        }

        return $column;
    }

    /**
     * Get the per-table database embedding settings.
     *
     * @param \Cake\ORM\Table $table Table
     * @return array<string, mixed>
     */
    protected function databaseEmbeddingSettings(Table $table): array
    {
        /** @var array<string, mixed> $settings */
        $settings = Configure::read('Explorator.database.model-settings.' . $table::class, []);

        return $settings['embedding'] ?? [];
    }

    /**
     * Determine if a hybrid search should use vector search.
     */
    protected function shouldPerformHybridSearch(Builder $builder): bool
    {
        return !is_null($builder->hybridSearch)
            && method_exists($builder->table->getEntityClass(), 'toSearchableEmbedding')
            && $this->supportsVectorSearch($builder->table);
    }

    /**
     * Determine if the table's database supports vector queries.
     *
     * @param \Cake\ORM\Table $table Table
     */
    protected function supportsVectorSearch(Table $table): bool
    {
        return $this->connectionIsPgsql($table);
    }

    /**
     * Ensure semantic search is supported by the table's database.
     */
    protected function ensureSemanticSearchIsSupported(Builder $builder): void
    {
        if (!$this->supportsVectorSearch($builder->table)) {
            throw new ExploratorException('Database semantic search requires PostgreSQL with the pgvector extension.');
        }

        if (!method_exists($builder->table->getEntityClass(), 'toSearchableEmbedding')) {
            throw new ExploratorException('Database semantic search requires the entity to define a [toSearchableEmbedding] method.');
        }
    }

    /**
     * Apply a vector distance filter to the query (pgvector cosine distance).
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @param string $qualifiedColumn Fully-qualified column
     * @param array<int, float> $vector Embedding vector
     * @param string $operator Comparison operator (`<=` / `<`)
     * @param float $minSimilarity Minimum similarity threshold
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function applyVectorDistance(SelectQuery $query, string $qualifiedColumn, array $vector, string $operator, float $minSimilarity): SelectQuery
    {
        $threshold = 1 - $minSimilarity;

        $query->where("{$qualifiedColumn} <=> :__vector {$operator} :__vector_threshold");
        $query->bind(':__vector', json_encode($vector, JSON_THROW_ON_ERROR), 'string');
        $query->bind(':__vector_threshold', $threshold, 'float');

        return $query;
    }

    /**
     * Apply a vector distance ORDER BY expression to the query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @param string $qualifiedColumn Fully-qualified column
     * @param array<int, float> $vector Embedding vector
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function applyVectorOrder(SelectQuery $query, string $qualifiedColumn, array $vector): SelectQuery
    {
        $query->orderBy(new QueryExpression("{$qualifiedColumn} <=> :__vector"));
        $query->bind(':__vector', json_encode($vector, JSON_THROW_ON_ERROR), 'string');

        return $query;
    }

    /**
     * @param \Cake\ORM\Table $table Table
     */
    protected function connectionIsPgsql(Table $table): bool
    {
        $driverClass = $table->getConnection()->getDriver()::class;

        return str_contains($driverClass, 'Postgres');
    }
}
