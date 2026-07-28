<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engines;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Database\Expression\ComparisonExpression;
use Cake\Database\Expression\FunctionExpression;
use Cake\Database\Expression\QueryExpression;
use Cake\Database\Expression\UnaryExpression;
use Cake\Database\ExpressionInterface;
use Cake\Database\Query;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Closure;
use Crustum\Explorator\Attribute\SearchUsingFullText;
use Crustum\Explorator\Attribute\SearchUsingPrefix;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Database\Expression\MatchAgainstExpression;
use ReflectionMethod;

/**
 * Database LIKE / full-text search engine.
 */
class DatabaseEngine extends Engine
{
    /**
     * @inheritDoc
     */
    public function update(iterable $entities): void
    {
    }

    /**
     * @inheritDoc
     */
    public function delete(iterable $entities): void
    {
    }

    /**
     * @inheritDoc
     */
    public function search(Builder $builder): mixed
    {
        $entities = $this->searchEntities($builder)->toList();

        return [
            'results' => $entities,
            'total' => count($entities),
        ];
    }

    /**
     * @inheritDoc
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $query = $this->applySearchOrders($builder, $this->buildSearchQuery($builder));
        $total = $query->count();
        $results = $query
            ->limit($perPage)
            ->page($page)
            ->all()
            ->toList();

        return [
            'results' => $results,
            'total' => $total,
        ];
    }

    /**
     * Paginate without a total count (simple pagination).
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param int $perPage Page size
     * @param int $page Page number
     * @return array{results: list<\Cake\Datasource\EntityInterface>, total: int|null, hasMore: bool}
     */
    public function simplePaginate(Builder $builder, int $perPage, int $page): array
    {
        $query = $this->applySearchOrders($builder, $this->buildSearchQuery($builder));
        $results = $query
            ->limit($perPage + 1)
            ->offset(($page - 1) * $perPage)
            ->all()
            ->toList();
        $hasMore = count($results) > $perPage;

        if ($hasMore) {
            $results = array_slice($results, 0, $perPage);
        }

        return [
            'results' => $results,
            'total' => null,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    protected function searchEntities(Builder $builder): CollectionInterface
    {
        $query = $this->applySearchOrders($builder, $this->buildSearchQuery($builder));

        return collection($query->all()->toList());
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function buildSearchQuery(Builder $builder): SelectQuery
    {
        $columns = array_keys($this->searchableArray($builder));
        $query = $this->initializeSearchQuery(
            $builder,
            $columns,
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder),
        );
        $query = $this->addAdditionalConstraints($builder, $query);

        if ($builder->limit !== null) {
            $query = $query->limit($builder->limit);
        }

        return $this->constrainForSoftDeletes($builder, $query);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function applySearchOrders(Builder $builder, SelectQuery $query): SelectQuery
    {
        if ($builder->orders !== []) {
            $orders = [];
            foreach ($builder->orders as $order) {
                $orders[$order['column']] = strtolower($order['direction']) === 'asc' ? 'ASC' : 'DESC';
            }

            return $query->orderBy($orders);
        }

        if (!$this->shouldOrderByRelevance($builder)) {
            $keyName = $this->qualifyColumn($builder, $this->getExploratorKeyName($builder->table));

            return $query->orderBy([$keyName => 'DESC']);
        }

        return $this->orderByRelevance($builder, $query);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param list<string> $columns Searchable columns
     * @param list<string> $prefixColumns Prefix columns
     * @param list<string> $fullTextColumns Full-text columns
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function initializeSearchQuery(
        Builder $builder,
        array $columns,
        array $prefixColumns = [],
        array $fullTextColumns = [],
    ): SelectQuery {
        $table = $builder->table;
        $query = $this->newExploratorQuery($builder);

        if ($builder->query === '') {
            return $query;
        }

        $connectionType = $this->modelConnectionType($builder);
        $keyName = $this->getExploratorKeyName($table);
        $likeOperator = $connectionType === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $query->where(function (
            QueryExpression $exp,
            Query $query,
        ) use (
            $builder,
            $columns,
            $prefixColumns,
            $fullTextColumns,
            $connectionType,
            $keyName,
            $likeOperator,
        ): QueryExpression {
            $or = [];

            $canSearchPrimaryKey = ctype_digit($builder->query)
                && in_array($keyName, $columns, true)
                && ($connectionType !== 'pgsql' || (int)$builder->query <= PHP_INT_MAX);

            if ($canSearchPrimaryKey) {
                $or[] = [$this->qualifyColumn($builder, $keyName) => (int)$builder->query];
            }

            foreach ($columns as $column) {
                if (in_array($column, $fullTextColumns, true)) {
                    continue;
                }

                if ($canSearchPrimaryKey && $column === $keyName) {
                    continue;
                }

                if ($this->shouldSkipLikeColumn($builder, $column)) {
                    continue;
                }

                $pattern = in_array($column, $prefixColumns, true)
                    ? $builder->query . '%'
                    : '%' . $builder->query . '%';
                $or[] = [
                    $this->qualifyColumn($builder, $column) . ' ' . $likeOperator => $pattern,
                ];
            }

            if ($fullTextColumns !== []) {
                $this->appendFullTextConditions(
                    $builder,
                    $query,
                    $or,
                    $fullTextColumns,
                    $connectionType,
                );
            }

            if ($or === []) {
                return $exp;
            }

            return $exp->or($or);
        });
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param \Cake\Database\Query $query Query
     * @param list<array<string, mixed>|\Cake\Database\ExpressionInterface> $or OR conditions
     * @param list<string> $fullTextColumns Full-text columns
     * @param string $connectionType Driver name
     * @return void
     */
    protected function appendFullTextConditions(
        Builder $builder,
        Query $query,
        array &$or,
        array $fullTextColumns,
        string $connectionType,
    ): void {
        $qualifiedColumns = array_map(
            fn(string $column): string => $this->qualifyColumn($builder, $column),
            $fullTextColumns,
        );

        if ($connectionType === 'pgsql') {
            $or[] = $this->postgresFullTextMatch($builder, $qualifiedColumns);

            return;
        }

        if ($connectionType === 'mysql') {
            $or[] = new MatchAgainstExpression($qualifiedColumns, $builder->query);

            return;
        }

        foreach ($fullTextColumns as $column) {
            if ($this->shouldSkipLikeColumn($builder, $column)) {
                continue;
            }

            $or[] = [
                $this->qualifyColumn($builder, $column) . ' LIKE' => '%' . $builder->query . '%',
            ];
        }
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param string $column Column name
     * @return bool
     */
    protected function shouldSkipLikeColumn(Builder $builder, string $column): bool
    {
        $type = $builder->table->getSchema()->getColumnType($column);

        return in_array($type, [
            'integer',
            'biginteger',
            'tinyinteger',
            'smallinteger',
            'float',
            'decimal',
            'boolean',
            'datetime',
            'timestamp',
            'date',
            'time',
        ], true);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return bool
     */
    protected function shouldOrderByRelevance(Builder $builder): bool
    {
        return $this->modelConnectionType($builder) === 'pgsql'
            && $this->getFullTextColumns($builder) !== []
            && $builder->orders === [];
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function orderByRelevance(Builder $builder, SelectQuery $query): SelectQuery
    {
        $qualifiedColumns = array_map(
            fn(string $column): string => $this->qualifyColumn($builder, $column),
            $this->getFullTextColumns($builder),
        );

        return $query->orderByDesc(
            new FunctionExpression('ts_rank', [
                $this->postgresTsVectors($builder, $qualifiedColumns),
                $this->postgresTsQuery($builder),
            ]),
        );
    }

    /**
     * Postgres `(to_tsvector || …) @@ plainto_tsquery(?)` via Cake expression objects.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param list<string> $qualifiedColumns Qualified column names
     * @return \Cake\Database\ExpressionInterface
     */
    protected function postgresFullTextMatch(Builder $builder, array $qualifiedColumns): ExpressionInterface
    {
        return new ComparisonExpression(
            new UnaryExpression('', $this->postgresTsVectors($builder, $qualifiedColumns), UnaryExpression::POSTFIX),
            $this->postgresTsQuery($builder),
            null,
            '@@',
        );
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param list<string> $qualifiedColumns Qualified column names
     * @return \Cake\Database\Expression\QueryExpression
     */
    protected function postgresTsVectors(Builder $builder, array $qualifiedColumns): QueryExpression
    {
        $options = $this->getFullTextOptions($builder);
        $language = (string)($options['language'] ?? 'english');
        $vectors = new QueryExpression([], [], ' || ');

        foreach ($qualifiedColumns as $column) {
            $vectors->add(new FunctionExpression('to_tsvector', [
                $language,
                $column => 'identifier',
            ], ['string']));
        }

        return $vectors;
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\Database\Expression\FunctionExpression
     */
    protected function postgresTsQuery(Builder $builder): FunctionExpression
    {
        $options = $this->getFullTextOptions($builder);
        $mode = match ($options['mode'] ?? 'plainto_tsquery') {
            'phrase' => 'phraseto_tsquery',
            'websearch' => 'websearch_to_tsquery',
            default => 'plainto_tsquery',
        };

        return new FunctionExpression($mode, [$builder->query], ['string']);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function addAdditionalConstraints(Builder $builder, SelectQuery $query): SelectQuery
    {
        if ($builder->callback instanceof Closure) {
            ($builder->callback)($query, $builder, $builder->query);

            return $query;
        }

        foreach ($builder->wheres as $where) {
            if ($where['field'] === '__soft_deleted') {
                continue;
            }

            $this->applyWhere($query, $where['field'], $where['operator'], $where['value']);
        }

        foreach ($builder->whereIns as $field => $values) {
            $query->whereInList($field, $values);
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $query->whereNotInList($field, $values);
        }

        if ($builder->queryCallback instanceof Closure) {
            ($builder->queryCallback)($query);
        }

        return $query;
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function constrainForSoftDeletes(Builder $builder, SelectQuery $query): SelectQuery
    {
        if (!$this->usesSoftDelete($builder->table)) {
            return $query;
        }

        $softDeleteWhere = array_find($builder->wheres, fn($where): bool => $where['field'] === '__soft_deleted');

        $query = $query->applyOptions(['withTrashed' => true]);
        $field = $builder->table->aliasField('deleted');

        if ($softDeleteWhere !== null && (int)$softDeleteWhere['value'] === 0) {
            return $query->where(static fn($exp) => $exp->isNull($field));
        }

        if ($softDeleteWhere !== null && (int)$softDeleteWhere['value'] === 1) {
            return $query->where(static fn($exp) => $exp->isNotNull($field));
        }

        return $query;
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return list<string>
     */
    protected function getFullTextColumns(Builder $builder): array
    {
        return $this->getAttributeColumns($builder, SearchUsingFullText::class);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return list<string>
     */
    protected function getPrefixColumns(Builder $builder): array
    {
        return $this->getAttributeColumns($builder, SearchUsingPrefix::class);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param class-string $attributeClass Attribute class
     * @return list<string>
     */
    protected function getAttributeColumns(Builder $builder, string $attributeClass): array
    {
        $entityClass = $builder->table->getEntityClass();
        if (!method_exists($entityClass, 'toSearchableArray')) {
            return [];
        }

        $columns = [];

        foreach ((new ReflectionMethod($entityClass, 'toSearchableArray'))->getAttributes() as $attribute) {
            if ($attribute->getName() !== $attributeClass) {
                continue;
            }

            $arguments = $attribute->getArguments();
            $value = $arguments['columns'] ?? $arguments[0] ?? [];
            if (is_string($value)) {
                $columns[] = $value;
            } elseif (is_array($value)) {
                $columns = array_merge($columns, $value);
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return array<string, mixed>
     */
    protected function getFullTextOptions(Builder $builder): array
    {
        $entityClass = $builder->table->getEntityClass();
        if (!method_exists($entityClass, 'toSearchableArray')) {
            return [];
        }

        $options = [];

        foreach ((new ReflectionMethod($entityClass, 'toSearchableArray'))->getAttributes(SearchUsingFullText::class) as $attribute) {
            $arguments = $attribute->getArguments();
            $value = $arguments['options'] ?? $arguments[1] ?? [];
            if (is_array($value)) {
                $options = array_merge($options, $value);
            }
        }

        return $options;
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return array<string, mixed>
     */
    protected function searchableArray(Builder $builder): array
    {
        $entity = $builder->table->newEmptyEntity();
        if (method_exists($entity, 'toSearchableArray')) {
            return $entity->toSearchableArray();
        }

        return $entity->toArray();
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function newExploratorQuery(Builder $builder): SelectQuery
    {
        $table = $builder->table;
        if (method_exists($table, 'newExploratorQuery')) {
            return $table->newExploratorQuery($builder);
        }

        return $table->find();
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param string $column Column name
     * @return string
     */
    protected function qualifyColumn(Builder $builder, string $column): string
    {
        return $builder->table->aliasField($column);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return string
     */
    protected function modelConnectionType(Builder $builder): string
    {
        $driverClass = $builder->table->getConnection()->getDriver()::class;

        return match (true) {
            str_contains($driverClass, 'Postgres') => 'pgsql',
            str_contains($driverClass, 'Mysql') => 'mysql',
            str_contains($driverClass, 'Sqlite') => 'sqlite',
            default => 'other',
        };
    }

    /**
     * @param \Cake\ORM\Table $table Table
     * @return bool
     */
    protected function usesSoftDelete(Table $table): bool
    {
        return $table->hasBehavior('SoftDelete');
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @param string $field Field
     * @param string $operator Operator
     * @param mixed $value Value
     * @return void
     */
    protected function applyWhere(SelectQuery $query, string $field, string $operator, mixed $value): void
    {
        $operator = strtolower($operator);

        match ($operator) {
            '=', 'eq' => $query->where([$field => $value]),
            '!=', '<>', 'neq' => $query->where(["{$field} !=" => $value]),
            '>' => $query->where(["{$field} >" => $value]),
            '>=' => $query->where(["{$field} >=" => $value]),
            '<' => $query->where(["{$field} <" => $value]),
            '<=' => $query->where(["{$field} <=" => $value]),
            default => $query->where(["{$field} {$operator}" => $value]),
        };
    }

    /**
     * @param \Cake\ORM\Table $table Table
     * @return string
     */
    protected function getExploratorKeyName(Table $table): string
    {
        if (method_exists($table, 'getExploratorKeyName')) {
            $key = $table->getExploratorKeyName();
            if (is_string($key)) {
                return $key;
            }
        }

        $primary = $table->getPrimaryKey();

        return is_array($primary) ? (string)$primary[0] : $primary;
    }

    /**
     * @inheritDoc
     */
    public function mapIds(mixed $results): CollectionInterface
    {
        $rows = $results['results'] ?? [];
        if ($rows === []) {
            return new Collection([]);
        }

        $list = is_array($rows) ? $rows : iterator_to_array($rows);
        if ($list === []) {
            return new Collection([]);
        }

        /** @var \Cake\Datasource\EntityInterface $first */
        $first = $list[0];
        $keyName = method_exists($first, 'getExploratorKeyName') ? $first->getExploratorKeyName() : 'id';

        return collection($list)->extract($keyName);
    }

    /**
     * @inheritDoc
     */
    public function map(Builder $builder, mixed $results): ResultSetInterface
    {
        $rows = $results['results'] ?? [];
        if ($rows instanceof ResultSetInterface) {
            return $rows;
        }

        $list = is_array($rows) ? $rows : iterator_to_array($rows);

        return new ResultSet($list);
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
        if (array_key_exists('total', $results) && $results['total'] === null) {
            return count($results['results'] ?? []);
        }

        return (int)($results['total'] ?? 0);
    }

    /**
     * @inheritDoc
     */
    public function flush(mixed $table): void
    {
    }

    /**
     * @inheritDoc
     */
    public function createIndex(string $name, array $options = []): mixed
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $name): mixed
    {
        return null;
    }
}
