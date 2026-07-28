<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engines;

use Cake\Collection\CollectionInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Contract\UpdatesIndexSettings;

/**
 * Shared Algolia engine behavior (Cake rewrite).
 */
abstract class AlgoliaEngine extends Engine implements UpdatesIndexSettings
{
    /**
     * @param object $algolia Algolia client
     * @param bool $softDelete Soft delete indexing
     */
    public function __construct(
        protected object $algolia,
        protected bool $softDelete = false,
    ) {
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Builder
     * @param array<string, mixed> $options Options
     * @return mixed
     */
    abstract protected function performSearch(Builder $builder, array $options = []): mixed;

    /**
     * @inheritDoc
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, array_filter([
            'filters' => $this->filters($builder),
            'numericFilters' => $this->numericFilters($builder),
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * @inheritDoc
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        return $this->performSearch($builder, [
            'filters' => $this->filters($builder),
            'numericFilters' => $this->numericFilters($builder),
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Builder
     * @return string|null
     */
    protected function filters(Builder $builder): ?string
    {
        $filters = [];
        foreach ($builder->wheres as $where) {
            $field = (string)$where['field'];
            $operator = (string)$where['operator'];
            $value = $where['value'];

            if (is_string($value) || (is_bool($value) && in_array($operator, ['=', '!='], true))) {
                $formatted = $this->formatFilterValue($value);
                $filters[] = $operator === '!=' ? 'NOT ' . $field . ':' . $formatted : $field . ':' . $formatted;

                continue;
            }

            if ($operator === '=') {
                $filters[] = $field . ":'" . $value . "'";
                continue;
            }

            $filters[] = $field . $operator . $value;
        }

        foreach ($builder->whereIns as $field => $values) {
            if ($values === []) {
                $filters[] = '0:1';
                continue;
            }

            $parts = [];
            foreach ($values as $value) {
                $parts[] = $field . ':' . $this->formatFilterValue($value);
            }

            $filters[] = '(' . implode(' OR ', $parts) . ')';
        }

        foreach ($builder->whereNotIns as $field => $values) {
            if ($values === []) {
                continue;
            }

            $parts = [];
            foreach ($values as $value) {
                $parts[] = 'NOT ' . $field . ':' . $this->formatFilterValue($value);
            }

            $filters[] = implode(' AND ', $parts);
        }

        return $filters === [] ? null : implode(' AND ', $filters);
    }

    /**
     * Format a value for an Algolia filter expression.
     *
     * @param mixed $value Raw filter value
     * @return string
     */
    protected function formatFilterValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$value) . "'";
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Builder
     * @return list<string>|null
     */
    protected function numericFilters(Builder $builder): ?array
    {
        return null;
    }

    /**
     * @param \Cake\ORM\Table $table Table
     * @return string
     */
    protected function indexName(Table $table): string
    {
        if (method_exists($table, 'searchableAs')) {
            return $table->searchableAs();
        }

        return $table->getTable();
    }

    /**
     * @inheritDoc
     */
    public function mapIds(mixed $results): CollectionInterface
    {
        $hits = $results['hits'] ?? [];

        return collection($hits)->extract('objectID');
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

        $ids = collection($hits)->extract('objectID')->toList();
        $positions = array_flip($ids);
        $keyName = method_exists($builder->table, 'getExploratorKeyName')
            ? $builder->table->getExploratorKeyName()
            : 'id';

        $hydrated = [];
        if (method_exists($builder->table, 'getExploratorModelsByIds')) {
            $hydrated = $builder->table->getExploratorModelsByIds($builder, $ids)->toList();
        } else {
            $hydrated = $builder->table->find()->whereInList($keyName, $ids)->all()->toList();
        }

        usort($hydrated, static fn(EntityInterface $a, EntityInterface $b): int => ($positions[$a->get($keyName)] ?? 0) <=> ($positions[$b->get($keyName)] ?? 0));

        foreach ($hydrated as $entity) {
            $exploratorKey = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);
            $hit = $hits[$positions[$exploratorKey] ?? -1] ?? [];
            if (!is_array($hit)) {
                continue;
            }

            if (!method_exists($entity, 'withExploratorMetadata')) {
                continue;
            }

            foreach ($hit as $metaKey => $value) {
                if (is_string($metaKey) && str_starts_with($metaKey, '_')) {
                    $entity->withExploratorMetadata($metaKey, $value);
                }
            }
        }

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
        return (int)($results['nbHits'] ?? 0);
    }

    /**
     * @inheritDoc
     */
    public function flush(mixed $table): void
    {
        if (!$table instanceof Table) {
            return;
        }

        $this->deleteIndex($this->indexName($table));
        $this->createIndex($this->indexName($table));
    }

    /**
     * @inheritDoc
     */
    abstract public function updateIndexSettings(string $name, array $settings = []): void;

    /**
     * @inheritDoc
     */
    public function configureSoftDeleteFilter(array $settings = []): array
    {
        $settings['attributesForFaceting'] ??= [];
        $settings['attributesForFaceting'][] = 'filterOnly(__soft_deleted)';

        return $settings;
    }
}
