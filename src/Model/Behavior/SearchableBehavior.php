<?php
declare(strict_types=1);

namespace Crustum\Explorator\Model\Behavior;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Table;
use Closure;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\SearchableIndexer;
use Override;

/**
 * Auto-sync Explorator indexes on save/delete (events only — not the handy public API).
 */
class SearchableBehavior extends Behavior
{
    /**
     * Tables (aliases or class names) with syncing disabled.
     *
     * @var array<string, bool>
     */
    protected static array $syncingDisabledFor = [];

    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'implementedFinders' => [],
        'implementedMethods' => [],
    ];

    /**
     * @var bool
     */
    protected bool $forceSaving = false;

    /**
     * @inheritDoc
     */
    #[Override]
    public function implementedEvents(): array
    {
        return [
            'Model.afterSave' => 'afterSave',
            'Model.afterDelete' => 'afterDelete',
            'Model.afterRestore' => 'afterRestore',
        ];
    }

    /**
     * Enable syncing for a table alias or class.
     *
     * @param string $key Table alias or class name
     * @return void
     */
    public static function enableSyncingFor(string $key): void
    {
        unset(static::$syncingDisabledFor[$key]);
    }

    /**
     * Disable syncing for a table alias or class.
     *
     * @param string $key Table alias or class name
     * @return void
     */
    public static function disableSyncingFor(string $key): void
    {
        static::$syncingDisabledFor[$key] = true;
    }

    /**
     * @param object|string $tableOrKey Table instance, alias, or class
     * @return bool
     */
    public static function syncingDisabledFor(object|string $tableOrKey): bool
    {
        if ($tableOrKey instanceof Table) {
            return isset(static::$syncingDisabledFor[$tableOrKey->getAlias()])
                || isset(static::$syncingDisabledFor[$tableOrKey::class]);
        }

        return isset(static::$syncingDisabledFor[$tableOrKey]);
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event Event
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject<string, mixed> $options Options
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        unset($event, $options);

        if (static::syncingDisabledFor($this->table())) {
            return;
        }

        if (!$this->forceSaving && !$this->searchIndexShouldBeUpdated($entity)) {
            return;
        }

        $this->runPossiblyAfterCommit(function () use ($entity): void {
            $this->syncSavedEntity($entity);
        });
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event Event
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject<string, mixed> $options Options
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        unset($event);

        if (static::syncingDisabledFor($this->table())) {
            return;
        }

        $usesSoftDelete = $this->usesSoftDelete();
        $forceDeleting = !empty($options['force']) || (bool)$entity->get('_forceDeleting');

        if ($usesSoftDelete && $forceDeleting) {
            $this->runPossiblyAfterCommit(function () use ($entity): void {
                $this->removeEntityFromSearch($entity);
            });

            return;
        }

        if (!$this->wasSearchableBeforeDelete($entity)) {
            return;
        }

        if (
            $usesSoftDelete
            && (bool)Configure::read('Explorator.soft_delete', false)
        ) {
            $this->runPossiblyAfterCommit(function () use ($entity): void {
                $this->whileForcingUpdate(function () use ($entity): void {
                    $this->syncSavedEntity($entity);
                });
            });

            return;
        }

        $this->runPossiblyAfterCommit(function () use ($entity): void {
            $this->removeEntityFromSearch($entity);
        });
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event Event
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject<string, mixed> $options Options
     * @return void
     */
    public function afterRestore(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        unset($event, $options);

        $this->runPossiblyAfterCommit(function () use ($entity): void {
            $this->whileForcingUpdate(function () use ($entity): void {
                $this->syncSavedEntity($entity);
            });
        });
    }

    /**
     * Run sync immediately or after the outermost DB transaction commits.
     *
     * @param \Closure $callback Sync callback
     * @return void
     */
    protected function runPossiblyAfterCommit(Closure $callback): void
    {
        if (!(bool)Configure::read('Explorator.after_commit', false)) {
            $callback();

            return;
        }

        $connection = $this->table()->getConnection();
        if ($connection->inTransaction()) {
            $connection->afterCommit($callback);

            return;
        }

        $callback();
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return void
     */
    protected function syncSavedEntity(EntityInterface $entity): void
    {
        $table = $this->table();
        if (method_exists($table, 'makeSearchable') && method_exists($table, 'removeFromSearch')) {
            if (!$this->shouldBeSearchable($entity)) {
                if ($this->wasSearchableBeforeUpdate($entity)) {
                    $table->removeFromSearch([$entity]);
                }

                return;
            }

            $table->makeSearchable([$entity]);

            return;
        }

        $indexer = $this->indexer();

        if (!$this->shouldBeSearchable($entity)) {
            if ($this->wasSearchableBeforeUpdate($entity)) {
                $indexer->removeFromSearch([$entity]);
            }

            return;
        }

        $indexer->makeSearchable([$entity]);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return void
     */
    protected function removeEntityFromSearch(EntityInterface $entity): void
    {
        $table = $this->table();
        if (method_exists($table, 'removeFromSearch')) {
            $table->removeFromSearch([$entity]);

            return;
        }

        $this->indexer()->removeFromSearch([$entity]);
    }

    /**
     * @param \Closure $callback Callback
     * @return mixed
     */
    protected function whileForcingUpdate(Closure $callback): mixed
    {
        $this->forceSaving = true;

        try {
            return $callback();
        } finally {
            $this->forceSaving = false;
        }
    }

    /**
     * @return bool
     */
    protected function usesSoftDelete(): bool
    {
        return $this->table()->hasBehavior('SoftDelete');
    }

    /**
     * @return \Crustum\Explorator\SearchableIndexer
     */
    protected function indexer(): SearchableIndexer
    {
        return new SearchableIndexer(new EngineManager());
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
     * @return bool
     */
    protected function searchIndexShouldBeUpdated(EntityInterface $entity): bool
    {
        if (method_exists($entity, 'searchIndexShouldBeUpdated')) {
            return (bool)$entity->searchIndexShouldBeUpdated();
        }

        return true;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return bool
     */
    protected function wasSearchableBeforeUpdate(EntityInterface $entity): bool
    {
        if (method_exists($entity, 'wasSearchableBeforeUpdate')) {
            return (bool)$entity->wasSearchableBeforeUpdate();
        }

        return true;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return bool
     */
    protected function wasSearchableBeforeDelete(EntityInterface $entity): bool
    {
        if (method_exists($entity, 'wasSearchableBeforeDelete')) {
            return (bool)$entity->wasSearchableBeforeDelete();
        }

        return true;
    }
}
