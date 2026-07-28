<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engines;

use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\ResultSet;
use Crustum\Explorator\Builder;

/**
 * Null Explorator engine — no-op indexing and empty search results.
 */
class NullEngine extends Engine
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
        return [];
    }

    /**
     * @inheritDoc
     */
    public function paginate(Builder $builder, int $perPage, int $page): mixed
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function mapIds(mixed $results): CollectionInterface
    {
        return new Collection([]);
    }

    /**
     * @inheritDoc
     */
    public function map(Builder $builder, mixed $results): ResultSetInterface
    {
        return new ResultSet([]);
    }

    /**
     * @inheritDoc
     */
    public function lazyMap(Builder $builder, mixed $results): CollectionInterface
    {
        return new Collection([]);
    }

    /**
     * @inheritDoc
     */
    public function getTotalCount(mixed $results): int
    {
        return is_countable($results) ? count($results) : 0;
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
        return [];
    }

    /**
     * @inheritDoc
     */
    public function deleteIndex(string $name): mixed
    {
        return [];
    }
}
