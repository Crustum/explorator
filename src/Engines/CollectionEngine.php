<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engines;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Closure;
use Crustum\Explorator\Builder;

/**
 * In-memory collection search engine.
 *
 * Loads candidate rows via the Table query, then filters by `toSearchableArray()`
 * substring match.
 */
class CollectionEngine extends Engine
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
        $entities = $this->searchEntities($builder);
        if ($builder->limit !== null) {
            $entities = $entities->take($builder->limit);
        }

        $results = $entities->toList();

        return [
            'results' => $results,
            'total' => count($results),
        ];
    }

    /**
     * @inheritDoc
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        $entities = $this->searchEntities($builder);
        $total = $entities->count();
        $results = $entities
            ->skip(max(0, ($page - 1) * $perPage))
            ->take($perPage)
            ->toList();

        return [
            'results' => $results,
            'total' => $total,
        ];
    }

    /**
     * Load and filter searchable entities for the builder.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    protected function searchEntities(Builder $builder): CollectionInterface
    {
        $query = $this->buildBaseQuery($builder);
        $entities = collection($query->all()->toList());

        if ($entities->isEmpty()) {
            return $entities;
        }

        /** @var \Cake\Datasource\EntityInterface $first */
        $first = $entities->first();
        $prepared = $this->makeSearchableUsing($first, $entities);

        return collection($prepared)->filter(function (EntityInterface $entity) use ($builder): bool {
            if (!$this->shouldBeSearchable($entity)) {
                return false;
            }

            if ($builder->query === '') {
                return true;
            }

            $needle = mb_strtolower($builder->query);
            foreach ($this->toSearchableArray($entity) as $value) {
                if (!is_scalar($value)) {
                    $value = json_encode($value);
                }

                if ($value !== false && str_contains(mb_strtolower((string)$value), $needle)) {
                    return true;
                }
            }

            return false;
        })->compile(false);
    }

    /**
     * Build the ORM query used before in-memory searchable filtering.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function buildBaseQuery(Builder $builder): SelectQuery
    {
        $table = $builder->table;
        $query = $table->find();

        if ($builder->callback instanceof Closure) {
            ($builder->callback)($query, $builder, $builder->query);

            return $this->applyDefaultOrder($builder, $query);
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

        return $this->applyDefaultOrder($builder, $query);
    }

    /**
     * Apply Explorator where operators onto a Cake SelectQuery.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @param string $field Field name
     * @param string $operator Comparison operator
     * @param mixed $value Compared value
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
     * Apply builder orders or default explorator key descending.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function applyDefaultOrder(Builder $builder, SelectQuery $query): SelectQuery
    {
        if ($builder->orders !== []) {
            $orders = [];
            foreach ($builder->orders as $order) {
                $orders[$order['column']] = strtolower($order['direction']) === 'asc' ? 'ASC' : 'DESC';
            }

            return $query->orderBy($orders);
        }

        $keyName = $this->getExploratorKeyName($builder->table);

        return $query->orderBy([$keyName => 'DESC']);
    }

    /**
     * @inheritDoc
     */
    public function mapIds(mixed $results): CollectionInterface
    {
        $rows = array_values($results['results'] ?? []);
        if ($rows === []) {
            return new Collection([]);
        }

        /** @var \Cake\Datasource\EntityInterface $first */
        $first = $rows[0];

        return collection($rows)->extract($this->getExploratorKeyNameFromEntity($first));
    }

    /**
     * @inheritDoc
     */
    public function map(Builder $builder, mixed $results): ResultSetInterface
    {
        $rows = $results['results'] ?? [];
        if ($rows === []) {
            return new ResultSet([]);
        }

        $keyName = $this->getExploratorKeyName($builder->table);
        $objectIds = collection($rows)->extract($keyName)->toList();
        $objectIdPositions = array_flip($objectIds);

        $hydrated = $this->getExploratorModelsByIds($builder, $objectIds)
            ->filter(fn(EntityInterface $entity): bool => in_array($entity->get($keyName), $objectIds, true))
            ->sortBy(fn(EntityInterface $entity): int => $objectIdPositions[$entity->get($keyName)], SORT_ASC)
            ->toList();

        return new ResultSet($hydrated);
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

    /**
     * Resolve explorator key column for a table.
     *
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
     * Resolve explorator key column from an entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return string
     */
    protected function getExploratorKeyNameFromEntity(EntityInterface $entity): string
    {
        if (method_exists($entity, 'getExploratorKeyName')) {
            return (string)$entity->getExploratorKeyName();
        }

        return 'id';
    }

    /**
     * @param \Cake\Datasource\EntityInterface $first First entity
     * @param \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface> $entities Entities
     * @return iterable<\Cake\Datasource\EntityInterface>
     */
    protected function makeSearchableUsing(
        EntityInterface $first,
        CollectionInterface $entities,
    ): iterable {
        if (method_exists($first, 'makeSearchableUsing')) {
            return $first->makeSearchableUsing($entities);
        }

        return $entities;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return bool
     */
    protected function shouldBeSearchable(EntityInterface $entity): bool
    {
        if (method_exists($entity, 'shouldBeSearchable')) {
            return (bool)$entity->shouldBeSearchable();
        }

        return true;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return array<string, mixed>
     */
    protected function toSearchableArray(EntityInterface $entity): array
    {
        if (method_exists($entity, 'toSearchableArray')) {
            return $entity->toSearchableArray();
        }

        return $entity->toArray();
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param list<mixed> $ids Explorator keys
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    protected function getExploratorModelsByIds(Builder $builder, array $ids): CollectionInterface
    {
        $table = $builder->table;
        if (method_exists($table, 'getExploratorModelsByIds')) {
            return collection($table->getExploratorModelsByIds($builder, $ids));
        }

        $keyName = $this->getExploratorKeyName($table);
        $query = $table->find()->whereInList($keyName, $ids);
        if ($builder->queryCallback instanceof Closure) {
            ($builder->queryCallback)($query);
        }

        return collection($query->all()->toList());
    }
}
