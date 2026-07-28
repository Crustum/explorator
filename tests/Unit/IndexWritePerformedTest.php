<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Datasource\EntityInterface;
use Cake\Event\EventManager;
use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Engines\NullEngine;
use Crustum\Explorator\Event\IndexWritePerformed;
use Crustum\Explorator\SearchableIndexer;
use Mockery as m;

/**
 * Unit tests for Explorator IndexWritePerformed instrumentation.
 */
class IndexWritePerformedTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        EventManager::instance()->off(IndexWritePerformed::NAME);
        m::close();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testMakeSearchableSyncDispatchesUpdate(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('update')->once();

        $table = new class (['alias' => 'Articles', 'table' => 'articles']) extends Table {
            /**
             * @return string
             */
            public function searchableAs(): string
            {
                return 'articles_index';
            }
        };
        $this->getTableLocator()->set('Articles', $table);

        /** @var list<\Crustum\Explorator\Event\IndexWritePerformed> $seen */
        $seen = [];
        EventManager::instance()->on(
            IndexWritePerformed::NAME,
            function (IndexWritePerformed $event) use (&$seen): void {
                $seen[] = $event;
            },
        );

        $entity = new Entity(['id' => 1, 'title' => 'Hello']);
        $entity->setSource('Articles');
        $entity->setNew(false);

        $indexer = new class ($engine) extends SearchableIndexer {
            /**
             * @param \Crustum\Explorator\Engines\Engine $engine Engine
             */
            public function __construct(protected Engine $fixedEngine)
            {
                parent::__construct(new EngineManager());
            }

            /**
             * @param \Cake\Datasource\EntityInterface $entity Entity
             * @return \Crustum\Explorator\Engines\Engine
             */
            protected function engineFor(EntityInterface $entity): Engine
            {
                return $this->fixedEngine;
            }
        };

        $indexer->makeSearchableSync([$entity]);

        $this->assertCount(1, $seen);
        $this->assertSame('update', $seen[0]->getOperation());
        $this->assertSame(1, $seen[0]->getCount());
        $this->assertSame('articles_index', $seen[0]->getIndex());
        $this->assertGreaterThanOrEqual(0.0, $seen[0]->getDurationMs());
        $this->assertSame($table, $seen[0]->getSubject());
        $this->assertSame([
            'source' => 'Articles',
            'ids' => [1],
        ], $seen[0]->getData('request'));
        $this->assertSame([
            'operation' => 'update',
            'count' => 1,
            'index' => 'articles_index',
        ], $seen[0]->getData('response'));
    }

    /**
     * @return void
     */
    public function testRemoveFromSearchSyncDispatchesDelete(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('delete')->once();

        /** @var list<\Crustum\Explorator\Event\IndexWritePerformed> $seen */
        $seen = [];
        EventManager::instance()->on(
            IndexWritePerformed::NAME,
            function (IndexWritePerformed $event) use (&$seen): void {
                $seen[] = $event;
            },
        );

        $entity = new Entity(['id' => 2]);
        $entity->setSource('Articles');

        $indexer = new class ($engine) extends SearchableIndexer {
            /**
             * @param \Crustum\Explorator\Engines\Engine $engine Engine
             */
            public function __construct(protected Engine $fixedEngine)
            {
                parent::__construct(new EngineManager());
            }

            /**
             * @param \Cake\Datasource\EntityInterface $entity Entity
             * @return \Crustum\Explorator\Engines\Engine
             */
            protected function engineFor(EntityInterface $entity): Engine
            {
                return $this->fixedEngine;
            }
        };

        $indexer->removeFromSearchSync([$entity]);

        $this->assertCount(1, $seen);
        $this->assertSame('delete', $seen[0]->getOperation());
        $this->assertSame(1, $seen[0]->getCount());
    }

    /**
     * @return void
     */
    public function testNoListenersSkipsTiming(): void
    {
        $engine = new NullEngine();
        $entity = new Entity(['id' => 3]);
        $entity->setSource('Articles');

        $indexer = new class ($engine) extends SearchableIndexer {
            /**
             * @param \Crustum\Explorator\Engines\Engine $engine Engine
             */
            public function __construct(protected Engine $fixedEngine)
            {
                parent::__construct(new EngineManager());
            }

            /**
             * @param \Cake\Datasource\EntityInterface $entity Entity
             * @return \Crustum\Explorator\Engines\Engine
             */
            protected function engineFor(EntityInterface $entity): Engine
            {
                return $this->fixedEngine;
            }
        };

        $indexer->makeSearchableSync([$entity]);
        $this->assertTrue(true);
    }
}
