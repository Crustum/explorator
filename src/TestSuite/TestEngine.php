<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Closure;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\NullEngine;
use Override;
use Throwable;

/**
 * Recording Explorator engine for tests — captures writes/searches instead of remote I/O.
 *
 * Usage:
 * ```
 * Configure::write('Explorator.driver', 'test');
 * // … index / search …
 * $ops = TestEngine::getOperations();
 * ```
 */
class TestEngine extends NullEngine
{
    /**
     * Captured index/search operations.
     *
     * @var list<array{
     *     operation: string,
     *     table: string,
     *     index: string|null,
     *     keys: list<mixed>,
     *     payloads: list<array<string, mixed>>,
     *     query: string|null,
     *     wheres: list<array{field: string, operator: string, value: mixed}>,
     *     whereIns: array<string, list<mixed>>,
     *     whereNotIns: array<string, list<mixed>>,
     *     engine: string,
     *     timestamp: int
     * }>
     */
    protected static array $operations = [];

    /**
     * Stubbed raw search results returned by {@see search()} / {@see paginate()}.
     *
     * @var list<array<string, mixed>>
     */
    protected static array $searchResults = [];

    /**
     * @inheritDoc
     */
    public function update(iterable $entities): void
    {
        $this->recordWrite('update', $entities);
    }

    /**
     * @inheritDoc
     */
    public function delete(iterable $entities): void
    {
        $this->recordWrite('delete', $entities);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function search(Builder $builder): mixed
    {
        $this->recordSearch('search', $builder);

        if ($builder->callback instanceof Closure) {
            return ($builder->callback)(new TestIndex(self::$searchResults), $builder->query, $builder->options);
        }

        return self::$searchResults;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $this->recordSearch('paginate', $builder);

        if ($builder->callback instanceof Closure) {
            return ($builder->callback)(new TestIndex(self::$searchResults), $builder->query, $builder->options);
        }

        return self::$searchResults;
    }

    /**
     * Stub the raw results returned by search() / paginate().
     *
     * @param list<array<string, mixed>> $hits Raw hits (e.g. Meilisearch-shaped rows)
     * @return void
     */
    public static function setSearchResults(array $hits): void
    {
        self::$searchResults = $hits;
    }

    /**
     * Reset stubbed search results to empty.
     *
     * @return void
     */
    public static function clearSearchResults(): void
    {
        self::$searchResults = [];
    }

    /**
     * @inheritDoc
     */
    public function flush(mixed $table): void
    {
        $alias = '';
        $index = null;

        if ($table instanceof Table) {
            $alias = $table->getAlias();
            $index = method_exists($table, 'searchableAs')
                ? $table->searchableAs()
                : $table->getTable();
        } elseif (is_string($table)) {
            $alias = $table;
            $index = $table;
        }

        static::$operations[] = [
            'operation' => 'flush',
            'table' => $alias,
            'index' => $index,
            'keys' => [],
            'payloads' => [],
            'query' => null,
            'wheres' => [],
            'whereIns' => [],
            'whereNotIns' => [],
            'engine' => 'test',
            'timestamp' => time(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getOperations(): array
    {
        return static::$operations;
    }

    /**
     * @return void
     */
    public static function clearOperations(): void
    {
        static::$operations = [];
    }

    /**
     * @param string $operation Operation name
     * @return list<array<string, mixed>>
     */
    public static function getOperationsByType(string $operation): array
    {
        return array_values(array_filter(
            static::$operations,
            static fn(array $row): bool => $row['operation'] === $operation,
        ));
    }

    /**
     * @param string $table Table alias / source
     * @return list<array<string, mixed>>
     */
    public static function getOperationsForTable(string $table): array
    {
        return array_values(array_filter(
            static::$operations,
            static fn(array $row): bool => $row['table'] === $table,
        ));
    }

    /**
     * @param string $table Table alias / source
     * @return list<array<string, mixed>>
     */
    public static function getUpdatesForTable(string $table): array
    {
        return array_values(array_filter(
            static::$operations,
            static fn(array $row): bool => $row['operation'] === 'update' && $row['table'] === $table,
        ));
    }

    /**
     * @param string $table Table alias / source
     * @return list<array<string, mixed>>
     */
    public static function getDeletesForTable(string $table): array
    {
        return array_values(array_filter(
            static::$operations,
            static fn(array $row): bool => $row['operation'] === 'delete' && $row['table'] === $table,
        ));
    }

    /**
     * Write operations only (update, delete, flush).
     *
     * @return list<array<string, mixed>>
     */
    public static function getWrites(): array
    {
        return array_values(array_filter(
            static::$operations,
            static fn(array $row): bool => in_array($row['operation'], ['update', 'delete', 'flush'], true),
        ));
    }

    /**
     * Search / paginate operations.
     *
     * @return list<array<string, mixed>>
     */
    public static function getSearches(): array
    {
        return array_values(array_filter(
            static::$operations,
            static fn(array $row): bool => in_array($row['operation'], ['search', 'paginate'], true),
        ));
    }

    /**
     * @param string $operation update|delete
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    protected function recordWrite(string $operation, iterable $entities): void
    {
        $list = [];
        foreach ($entities as $entity) {
            $list[] = $entity;
        }

        if ($list === []) {
            return;
        }

        $first = $list[0];
        $tableAlias = $first->getSource();
        $resolved = $this->resolveTable($first);
        $index = $this->resolveIndex($first, $resolved);

        $keys = [];
        $payloads = [];
        foreach ($list as $entity) {
            $keys[] = method_exists($entity, 'getExploratorKey')
                ? $entity->getExploratorKey()
                : $entity->get('id');

            if ($operation === 'update') {
                $payloads[] = method_exists($entity, 'toSearchableArray')
                    ? $entity->toSearchableArray()
                    : $entity->toArray();
            }
        }

        static::$operations[] = [
            'operation' => $operation,
            'table' => $tableAlias,
            'index' => $index,
            'keys' => $keys,
            'payloads' => $payloads,
            'query' => null,
            'wheres' => [],
            'whereIns' => [],
            'whereNotIns' => [],
            'engine' => 'test',
            'timestamp' => time(),
        ];
    }

    /**
     * @param string $operation search|paginate
     * @param \Crustum\Explorator\Builder $builder Builder
     * @return void
     */
    protected function recordSearch(string $operation, Builder $builder): void
    {
        $table = $builder->table;
        $alias = $table->getAlias();
        $index = $builder->index
            ?? (method_exists($table, 'searchableAs') ? $table->searchableAs() : $table->getTable());

        static::$operations[] = [
            'operation' => $operation,
            'table' => $alias,
            'index' => $index,
            'keys' => [],
            'payloads' => [],
            'query' => $builder->query,
            'wheres' => $builder->wheres,
            'whereIns' => $builder->whereIns,
            'whereNotIns' => $builder->whereNotIns,
            'engine' => 'test',
            'timestamp' => time(),
        ];
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return \Cake\ORM\Table|null
     */
    protected function resolveTable(EntityInterface $entity): ?Table
    {
        $source = $entity->getSource();
        if ($source === '') {
            return null;
        }

        try {
            return TableRegistry::getTableLocator()->get($source);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \Cake\ORM\Table|null $table Table
     * @return string|null
     */
    protected function resolveIndex(EntityInterface $entity, ?Table $table): ?string
    {
        if ($table instanceof Table && method_exists($table, 'indexableAs')) {
            return $table->indexableAs();
        }

        if ($table instanceof Table && method_exists($table, 'searchableAs')) {
            return $table->searchableAs();
        }

        if ($table instanceof Table) {
            return $table->getTable();
        }

        $source = $entity->getSource();

        return $source !== '' ? $source : null;
    }
}
