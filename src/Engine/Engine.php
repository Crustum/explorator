<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engine;

use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Contract\SupportsSemanticSearch;
use Crustum\Explorator\Exception\ExploratorException;
use Crustum\Explorator\SearchInstrumentation;

/**
 * Abstract Explorator search engine.
 */
abstract class Engine
{
    use LocatorAwareTrait;

    /**
     * Whether write operations should wait until the remote index task is published.
     *
     * Default false (async). Enable for Integration tests / CLI import when search
     * must see writes immediately. Local engines (database/collection/null) ignore this.
     *
     * @return bool
     */
    protected function shouldWaitForTasks(): bool
    {
        return (bool)Configure::read('Explorator.wait_for_tasks', false);
    }

    /**
     * Ensure semantic / hybrid search is only dispatched to a supporting engine.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return void
     * @throws \Crustum\Explorator\Exception\ExploratorException
     */
    protected function guardSemanticSupport(Builder $builder): void
    {
        if (
            ($builder->semanticSearch || $builder->hybridSearch !== null)
            && !$this instanceof SupportsSemanticSearch
        ) {
            throw new ExploratorException('The configured Explorator engine does not support semantic or hybrid search.');
        }
    }

    /**
     * Update the given entities in the index.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities to index
     * @return void
     */
    abstract public function update(iterable $entities): void;

    /**
     * Remove the given entities from the index.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities to remove
     * @return void
     */
    abstract public function delete(iterable $entities): void;

    /**
     * Perform the given search on the engine.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return mixed
     */
    abstract public function search(Builder $builder): mixed;

    /**
     * Perform the given paginated search on the engine.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param int $perPage Page size
     * @param int $page Page number (1-based)
     * @return mixed
     */
    abstract public function paginate(Builder $builder, int $perPage, int $page): mixed;

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param mixed $results Raw engine results
     * @return \Cake\Collection\CollectionInterface
     */
    abstract public function mapIds(mixed $results): CollectionInterface;

    /**
     * Map the given results to entity instances for the builder table.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param mixed $results Raw engine results
     * @return \Cake\Datasource\ResultSetInterface<\Cake\Datasource\EntityInterface>
     */
    abstract public function map(Builder $builder, mixed $results): ResultSetInterface;

    /**
     * Map the given results to a lazy collection of entities.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param mixed $results Raw engine results
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    abstract public function lazyMap(Builder $builder, mixed $results): CollectionInterface;

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param mixed $results Raw engine results
     * @return int
     */
    abstract public function getTotalCount(mixed $results): int;

    /**
     * Flush all of the table's records from the engine.
     *
     * @param mixed $table Table whose index should be flushed
     * @return void
     */
    abstract public function flush(mixed $table): void;

    /**
     * Create a search index.
     *
     * @param string $name Index name
     * @param array<string, mixed> $options Index options
     * @return mixed
     */
    abstract public function createIndex(string $name, array $options = []): mixed;

    /**
     * Delete a search index.
     *
     * @param string $name Index name
     * @return mixed
     */
    abstract public function deleteIndex(string $name): mixed;

    /**
     * Pluck and return the primary keys of the given results using the given key name.
     *
     * @param mixed $results Raw engine results
     * @param string $key Key name
     * @return \Cake\Collection\CollectionInterface
     */
    public function mapIdsFrom(mixed $results, string $key): CollectionInterface
    {
        return $this->mapIds($results);
    }

    /**
     * Get the results of the query as a collection of primary keys.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\Collection\CollectionInterface
     */
    public function keys(Builder $builder): CollectionInterface
    {
        $this->guardSemanticSupport($builder);

        return $this->mapIds(
            SearchInstrumentation::run(
                $builder,
                $this,
                fn(): mixed => $this->search($builder),
            ),
        );
    }

    /**
     * Get the results of the given query mapped onto entities.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\Datasource\ResultSetInterface<\Cake\Datasource\EntityInterface>
     */
    public function get(Builder $builder): ResultSetInterface
    {
        $this->guardSemanticSupport($builder);

        return $this->map(
            $builder,
            $builder->applyAfterRawSearchCallback(
                SearchInstrumentation::run(
                    $builder,
                    $this,
                    fn(): mixed => $this->search($builder),
                ),
            ),
        );
    }

    /**
     * Get a lazy collection for the given query mapped onto entities.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    public function cursor(Builder $builder): CollectionInterface
    {
        $this->guardSemanticSupport($builder);

        return $this->lazyMap(
            $builder,
            $builder->applyAfterRawSearchCallback(
                SearchInstrumentation::run(
                    $builder,
                    $this,
                    fn(): mixed => $this->search($builder),
                ),
            ),
        );
    }
}
