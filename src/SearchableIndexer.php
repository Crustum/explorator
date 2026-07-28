<?php
declare(strict_types=1);

namespace Crustum\Explorator;

use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Event\ModelsFlushed;
use Crustum\Explorator\Event\ModelsImported;
use Crustum\Explorator\Job\RemovableExploratorCollection;

/**
 * Shared batch indexing / removal used by SearchableTrait and Behavior.
 */
class SearchableIndexer
{
    use LocatorAwareTrait;

    /**
     * @param \Crustum\Explorator\EngineManager $engineManager Engine manager
     */
    public function __construct(
        protected EngineManager $engineManager,
    ) {
    }

    /**
     * Make entities searchable (queue when configured, else sync).
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function makeSearchable(iterable $entities): void
    {
        $collection = $this->normalize($entities);
        if ($collection->isEmpty()) {
            return;
        }

        if ($this->shouldQueue()) {
            $this->pushMakeSearchable($collection);

            return;
        }

        $this->makeSearchableSync($collection);
    }

    /**
     * Synchronously make entities searchable.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function makeSearchableSync(iterable $entities): void
    {
        $collection = $this->normalize($entities);
        if ($collection->isEmpty()) {
            return;
        }

        /** @var \Cake\Datasource\EntityInterface $first */
        $first = $collection->first();
        $prepared = method_exists($first, 'makeSearchableUsing')
            ? collection($first->makeSearchableUsing($collection))
            : $collection;

        if ($prepared->isEmpty()) {
            return;
        }

        /** @var \Cake\Datasource\EntityInterface $engineSource */
        $engineSource = $prepared->first();
        $engine = $this->engineFor($engineSource);
        IndexWriteInstrumentation::run(
            $prepared,
            $engine,
            static function () use ($engine, $prepared): void {
                $engine->update($prepared);
            },
            'update',
        );
    }

    /**
     * Remove entities from search (queue when configured, else sync).
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function removeFromSearch(iterable $entities): void
    {
        $collection = $this->normalize($entities);
        if ($collection->isEmpty()) {
            return;
        }

        if ($this->shouldQueue()) {
            $this->pushRemoveFromSearch($collection);

            return;
        }

        $this->removeFromSearchSync($collection);
    }

    /**
     * Synchronously remove entities from search.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    public function removeFromSearchSync(iterable $entities): void
    {
        $collection = $this->normalize($entities);
        if ($collection->isEmpty()) {
            return;
        }

        /** @var \Cake\Datasource\EntityInterface $first */
        $first = $collection->first();
        $engine = $this->engineFor($first);
        IndexWriteInstrumentation::run(
            $collection,
            $engine,
            static function () use ($engine, $collection): void {
                $engine->delete($collection);
            },
            'delete',
        );
    }

    /**
     * Chunk-import rows from a table query (or the full table).
     *
     * @param \Cake\ORM\Table $table Searchable table
     * @param \Cake\ORM\Query\SelectQuery|null $query Optional scoped query
     * @param int|null $chunk Chunk size
     * @return void
     */
    public function importSearchable(Table $table, ?SelectQuery $query = null, ?int $chunk = null): void
    {
        $chunk ??= (int)Configure::read('Explorator.chunk.searchable', 500);
        $query ??= $table->find();
        $keyName = method_exists($table, 'getExploratorKeyName')
            ? $table->getExploratorKeyName()
            : (string)$table->getPrimaryKey();

        $query = $query->orderBy([$keyName => 'ASC']);

        $offset = 0;
        while (true) {
            $page = collection(
                $query->cleanCopy()->limit($chunk)->offset($offset)->all()->toList(),
            );
            if ($page->isEmpty()) {
                break;
            }

            $searchable = $page->filter(fn(EntityInterface $entity): bool => !method_exists($entity, 'shouldBeSearchable') || $entity->shouldBeSearchable());
            $this->makeSearchable($searchable);
            $table->getEventManager()->dispatch(new ModelsImported($table, $searchable->toList()));
            $offset += $chunk;

            if ($page->count() < $chunk) {
                break;
            }
        }
    }

    /**
     * Chunk-remove rows from a table query (or the full table).
     *
     * @param \Cake\ORM\Table $table Searchable table
     * @param \Cake\ORM\Query\SelectQuery|null $query Optional scoped query
     * @param int|null $chunk Chunk size
     * @return void
     */
    public function flushSearchable(Table $table, ?SelectQuery $query = null, ?int $chunk = null): void
    {
        $chunk ??= (int)Configure::read('Explorator.chunk.unsearchable', 500);
        $query ??= $table->find();
        $keyName = method_exists($table, 'getExploratorKeyName')
            ? $table->getExploratorKeyName()
            : (string)$table->getPrimaryKey();

        $query = $query->orderBy([$keyName => 'ASC']);

        $offset = 0;
        while (true) {
            $page = collection(
                $query->cleanCopy()->limit($chunk)->offset($offset)->all()->toList(),
            );
            if ($page->isEmpty()) {
                break;
            }

            $this->removeFromSearch($page);
            $table->getEventManager()->dispatch(new ModelsFlushed($table, $page->toList()));
            $offset += $chunk;

            if ($page->count() < $chunk) {
                break;
            }
        }
    }

    /**
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    protected function normalize(iterable $entities): CollectionInterface
    {
        return collection($entities);
    }

    /**
     * @return bool
     */
    protected function shouldQueue(): bool
    {
        return (bool)Configure::read('Explorator.queue', false);
    }

    /**
     * @param \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    protected function pushMakeSearchable(CollectionInterface $entities): void
    {
        /** @var \Cake\Datasource\EntityInterface $first */
        $first = $entities->first();
        $source = (string)$first->getSource();
        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = method_exists($entity, 'getExploratorKey')
                ? $entity->getExploratorKey()
                : $entity->get('id');
        }

        Explorator::push(Explorator::$makeSearchableJob, [
            'source' => $source,
            'ids' => $ids,
        ], $this->queueOptionsFor($source));
    }

    /**
     * @param \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface> $entities Entities
     * @return void
     */
    protected function pushRemoveFromSearch(CollectionInterface $entities): void
    {
        $payload = RemovableExploratorCollection::fromEntities($entities);
        $rows = $payload->toList();
        if ($rows === []) {
            return;
        }

        $source = (string)$rows[0]['source'];
        Explorator::push(Explorator::$removeFromSearchJob, [
            'source' => $source,
            'ids' => $payload->exploratorKeys(),
        ], $this->queueOptionsFor($source));
    }

    /**
     * @param string $source Table alias
     * @return array{config?: string, queue?: string}
     */
    protected function queueOptionsFor(string $source): array
    {
        $options = [];
        if ($source === '') {
            return $options;
        }

        $table = $this->getTableLocator()->get($source);
        if (method_exists($table, 'syncWithSearchUsing')) {
            $config = $table->syncWithSearchUsing();
            if (is_string($config) && $config !== '') {
                $options['config'] = $config;
            }
        }

        if (method_exists($table, 'syncWithSearchUsingQueue')) {
            $queue = $table->syncWithSearchUsingQueue();
            if (is_string($queue) && $queue !== '') {
                $options['queue'] = $queue;
            }
        }

        return $options;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return \Crustum\Explorator\Engines\Engine
     */
    protected function engineFor(EntityInterface $entity): Engine
    {
        if (method_exists($entity, 'searchableUsing')) {
            return $entity->searchableUsing();
        }

        $source = $entity->getSource();
        if ($source !== '') {
            $table = $this->getTableLocator()->get($source);
            if (method_exists($table, 'searchableUsing')) {
                return $table->searchableUsing();
            }
        }

        return $this->engineManager->engine();
    }
}
