<?php
declare(strict_types=1);

namespace Crustum\Explorator\Model\Trait;

use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\ORM\Query\SelectQuery;
use Closure;
use Crustum\Explorator\Builder;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Model\Behavior\SearchableBehavior;
use Crustum\Explorator\SearchableIndexer;

/**
 * Table-side Explorator searchable API (handy methods — not Behavior public API).
 *
 * @mixin \Cake\ORM\Table
 */
trait SearchableTrait
{
    /**
     * Start a Explorator search against this table.
     *
     * @param string $query Search query
     * @param \Closure|null $callback Engine callback
     * @return \Crustum\Explorator\Builder
     */
    public function search(string $query = '', ?Closure $callback = null): Builder
    {
        $softDelete = (bool)Configure::read('Explorator.soft_delete', false);

        return new Builder(
            $this,
            $query,
            $callback,
            $softDelete,
            $this->exploratorEngineManager(),
        );
    }

    /**
     * Make entities searchable (queue or sync per Configure).
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function makeSearchable(iterable $entities): void
    {
        $this->exploratorIndexer()->makeSearchable($entities);
    }

    /**
     * Synchronously make entities searchable.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function makeSearchableSync(iterable $entities): void
    {
        $this->exploratorIndexer()->makeSearchableSync($entities);
    }

    /**
     * Remove entities from search (queue or sync per Configure).
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function removeFromSearch(iterable $entities): void
    {
        $this->exploratorIndexer()->removeFromSearch($entities);
    }

    /**
     * Synchronously remove entities from search.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function removeFromSearchSync(iterable $entities): void
    {
        $this->exploratorIndexer()->removeFromSearchSync($entities);
    }

    /**
     * Chunk-import rows (replaces Eloquent Builder `searchable` macro).
     *
     * @param \Cake\ORM\Query\SelectQuery|null $query Optional scoped query
     * @param int|null $chunk Chunk size
     * @return void
     */
    public function importSearchable(?SelectQuery $query = null, ?int $chunk = null): void
    {
        $this->exploratorIndexer()->importSearchable($this, $query, $chunk);
    }

    /**
     * Chunk-remove rows (replaces Eloquent Builder `unsearchable` macro).
     *
     * @param \Cake\ORM\Query\SelectQuery|null $query Optional scoped query
     * @param int|null $chunk Chunk size
     * @return void
     */
    public function flushSearchable(?SelectQuery $query = null, ?int $chunk = null): void
    {
        $this->exploratorIndexer()->flushSearchable($this, $query, $chunk);
    }

    /**
     * Temporarily disable auto-sync for this table.
     *
     * @param callable $callback Callback
     * @return mixed
     */
    public function withoutSyncingToSearch(callable $callback): mixed
    {
        $alias = $this->getAlias();
        SearchableBehavior::disableSyncingFor($alias);

        try {
            return $callback();
        } finally {
            SearchableBehavior::enableSyncingFor($alias);
        }
    }

    /**
     * Resolve the Explorator engine for this table.
     *
     * @return \Crustum\Explorator\Engines\Engine
     */
    public function searchableUsing(): Engine
    {
        return $this->exploratorEngineManager()->engine();
    }

    /**
     * Index name used when searching.
     *
     * @return string
     */
    public function searchableAs(): string
    {
        return Configure::read('Explorator.prefix', '') . $this->getTable();
    }

    /**
     * Index name used when writing documents (defaults to searchableAs).
     *
     * @return string
     */
    public function indexableAs(): string
    {
        return $this->searchableAs();
    }

    /**
     * Queue connection config name for Explorator sync jobs.
     *
     * @return string|null
     */
    public function syncWithSearchUsing(): ?string
    {
        $queue = Configure::read('Explorator.queue');
        if (is_array($queue) && isset($queue['connection'])) {
            return (string)$queue['connection'];
        }

        return null;
    }

    /**
     * Queue name for Explorator sync jobs.
     *
     * @return string|null
     */
    public function syncWithSearchUsingQueue(): ?string
    {
        $queue = Configure::read('Explorator.queue');
        if (is_array($queue) && isset($queue['queue'])) {
            return (string)$queue['queue'];
        }

        return null;
    }

    /**
     * Explorator key column name.
     *
     * @return string
     */
    public function getExploratorKeyName(): string
    {
        $primary = $this->getPrimaryKey();

        return is_array($primary) ? (string)$primary[0] : (string)$primary;
    }

    /**
     * Created-at column used by Builder::latest()/oldest().
     *
     * @return string
     */
    public function getCreatedAtColumn(): string
    {
        return 'created';
    }

    /**
     * Load entities by explorator keys for engine hydration.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param list<mixed> $ids Explorator keys
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    public function getExploratorModelsByIds(Builder $builder, array $ids): CollectionInterface
    {
        if ($ids === []) {
            return collection([]);
        }

        $query = $this->find()->whereInList($this->getExploratorKeyName(), $ids);
        if ($builder->queryCallback instanceof Closure) {
            ($builder->queryCallback)($query);
        }

        return collection($query->all()->toList());
    }

    /**
     * @return \Crustum\Explorator\SearchableIndexer
     */
    protected function exploratorIndexer(): SearchableIndexer
    {
        return new SearchableIndexer($this->exploratorEngineManager());
    }

    /**
     * @return \Crustum\Explorator\EngineManager
     */
    protected function exploratorEngineManager(): EngineManager
    {
        return new EngineManager();
    }
}
