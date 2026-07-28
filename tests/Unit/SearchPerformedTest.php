<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Event\EventManager;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Engines\NullEngine;
use Crustum\Explorator\Event\SearchPerformed;
use Mockery as m;

/**
 * Unit tests for Explorator SearchPerformed instrumentation.
 */
class SearchPerformedTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        EventManager::instance()->off(SearchPerformed::NAME);
        m::close();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testRawSearchDispatchesSearchPerformed(): void
    {
        $table = new class (['alias' => 'Articles', 'table' => 'articles']) extends Table {
            /**
             * @return \Crustum\Explorator\Engines\Engine
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_search_event_engine'];
            }

            /**
             * @return string
             */
            public function searchableAs(): string
            {
                return 'articles_index';
            }
        };

        $engine = m::mock(Engine::class);
        $GLOBALS['explorator_search_event_engine'] = $engine;
        $engine->shouldReceive('search')->once()->andReturn(['hits' => [1, 2, 3]]);
        $engine->shouldReceive('getTotalCount')->once()->andReturn(3);
        $engine->shouldReceive('mapIdsFrom')->once()->andReturn(collection([1, 2, 3]));

        /** @var list<\Crustum\Explorator\Event\SearchPerformed> $seen */
        $seen = [];
        EventManager::instance()->on(
            SearchPerformed::NAME,
            function (SearchPerformed $event) use (&$seen): void {
                $seen[] = $event;
            },
        );

        $builder = new Builder($table, 'hello world');
        $builder->raw();

        $this->assertCount(1, $seen);
        $event = $seen[0];
        $this->assertSame('hello world', $event->getQuery());
        $this->assertSame('articles_index', $event->getIndex());
        $this->assertSame(3, $event->getHits());
        $this->assertSame('search', $event->getOperation());
        $this->assertGreaterThanOrEqual(0.0, $event->getDurationMs());
        $this->assertSame($table, $event->getSubject());
        $request = $event->getData('request');
        $this->assertIsArray($request);
        $this->assertSame('hello world', $request['query']);
        $this->assertSame('articles_index', $request['index']);
        $response = $event->getData('response');
        $this->assertIsArray($response);
        $this->assertSame(3, $response['hits']);

        unset($GLOBALS['explorator_search_event_engine']);
    }

    /**
     * @return void
     */
    public function testGetUsesEngineInstrumentationOnce(): void
    {
        $table = new class (['alias' => 'Articles', 'table' => 'articles']) extends Table {
            /**
             * @return \Crustum\Explorator\Engines\NullEngine
             */
            public function searchableUsing(): NullEngine
            {
                return new NullEngine();
            }
        };

        /** @var list<\Crustum\Explorator\Event\SearchPerformed> $seen */
        $seen = [];
        EventManager::instance()->on(
            SearchPerformed::NAME,
            function (SearchPerformed $event) use (&$seen): void {
                $seen[] = $event;
            },
        );

        $builder = new Builder($table, 'null-query');
        $builder->get();

        $this->assertCount(1, $seen);
        $this->assertSame('NullEngine', $seen[0]->getEngine());
        $this->assertSame(0, $seen[0]->getHits());
    }

    /**
     * @return void
     */
    public function testPaginateDispatchesWithPageMeta(): void
    {
        $table = new class (['alias' => 'Dummy']) extends Table {
            /**
             * @return \Crustum\Explorator\Engines\Engine
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_search_event_engine'];
            }
        };

        $engine = m::mock(Engine::class);
        $GLOBALS['explorator_search_event_engine'] = $engine;

        $items = array_fill(0, 5, (object)['id' => 1]);
        $engine->shouldReceive('paginate')->once()->andReturn(['results' => $items, 'total' => 20]);
        $engine->shouldReceive('map')->once()->andReturn(new ResultSet($items));
        $engine->shouldReceive('getTotalCount')->twice()->andReturn(20);

        /** @var list<\Crustum\Explorator\Event\SearchPerformed> $seen */
        $seen = [];
        EventManager::instance()->on(
            SearchPerformed::NAME,
            function (SearchPerformed $event) use (&$seen): void {
                $seen[] = $event;
            },
        );

        $builder = new Builder($table, 'paged');
        $builder->paginate(5, 'page', 2);

        $this->assertCount(1, $seen);
        $this->assertSame('paginate', $seen[0]->getOperation());
        $this->assertSame(2, $seen[0]->getPage());
        $this->assertSame(5, $seen[0]->getPerPage());
        $this->assertSame(20, $seen[0]->getHits());

        unset($GLOBALS['explorator_search_event_engine']);
    }

    /**
     * @return void
     */
    public function testNoListenersSkipsTimingAndHitCount(): void
    {
        $table = new class (['alias' => 'Dummy']) extends Table {
            /**
             * @return \Crustum\Explorator\Engines\Engine
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_search_event_engine'];
            }
        };

        $engine = m::mock(Engine::class);
        $GLOBALS['explorator_search_event_engine'] = $engine;
        $engine->shouldReceive('search')->once()->andReturn(['hits' => []]);
        $engine->shouldReceive('getTotalCount')->never();

        $builder = new Builder($table, 'quiet');
        $builder->raw();

        unset($GLOBALS['explorator_search_event_engine']);
    }
}
