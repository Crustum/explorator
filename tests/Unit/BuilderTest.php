<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Datasource\Paging\PaginatedInterface;
use Cake\ORM\ResultSet;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\Engine;
use Mockery as m;

/**
 * Unit tests for Explorator Builder (Macroable cases intentionally skipped).
 */
class BuilderTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testPaginationCorrectlyHandlesPaginatedResults(): void
    {
        $table = new class (['alias' => 'Dummy']) extends Table {
            /**
             * @return \Crustum\Explorator\Engines\Engine
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_builder_engine'];
            }
        };

        $engine = m::mock(Engine::class);
        $GLOBALS['explorator_builder_engine'] = $engine;

        $items = array_fill(0, 15, (object)['id' => 1]);
        $engine->shouldReceive('paginate')->once()->andReturn(['results' => $items, 'total' => 16]);
        $engine->shouldReceive('map')->once()->andReturn(new ResultSet($items));
        $engine->shouldReceive('getTotalCount')->once()->andReturn(16);

        $builder = new Builder($table, 'zonda');
        $paginated = $builder->paginate();

        $this->assertInstanceOf(PaginatedInterface::class, $paginated);
        $this->assertSame(16, $paginated->totalCount());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
        $this->assertCount(15, $paginated);

        unset($GLOBALS['explorator_builder_engine']);
    }

    /**
     * @return void
     */
    public function testSimplePaginateReturnsPaginatedInterface(): void
    {
        $table = new class (['alias' => 'Dummy']) extends Table {
            /**
             * @return \Crustum\Explorator\Engines\Engine
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_builder_engine'];
            }
        };

        $engine = m::mock(Engine::class);
        $GLOBALS['explorator_builder_engine'] = $engine;

        $items = array_fill(0, 15, (object)['id' => 1]);
        $engine->shouldReceive('paginate')->once()->andReturn(['results' => $items, 'total' => 40]);
        $engine->shouldReceive('map')->once()->andReturn(new ResultSet($items));
        $engine->shouldReceive('getTotalCount')->once()->andReturn(40);

        $builder = new Builder($table, 'zonda');
        $paginated = $builder->simplePaginate(15, 'page', 1);

        $this->assertInstanceOf(PaginatedInterface::class, $paginated);
        $this->assertTrue($paginated->hasNextPage());
        $this->assertSame(40, $paginated->totalCount());

        unset($GLOBALS['explorator_builder_engine']);
    }

    /**
     * @return void
     */
    public function testPaginateRawReturnsRawPayloadItems(): void
    {
        $table = new class (['alias' => 'Dummy']) extends Table {
            /**
             * @return \Crustum\Explorator\Engines\Engine
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_builder_engine'];
            }
        };

        $engine = m::mock(Engine::class);
        $GLOBALS['explorator_builder_engine'] = $engine;

        $raw = ['hits' => [['id' => 1]], 'totalHits' => 1];
        $engine->shouldReceive('paginate')->once()->andReturn($raw);
        $engine->shouldReceive('getTotalCount')->once()->andReturn(1);

        $builder = new Builder($table, 'zonda');
        $paginated = $builder->paginateRaw();

        $this->assertInstanceOf(PaginatedInterface::class, $paginated);
        $this->assertSame(1, $paginated->totalCount());

        unset($GLOBALS['explorator_builder_engine']);
    }

    /**
     * @return void
     */
    public function testMacroableIsNotSupported(): void
    {
        $this->assertFalse(method_exists(Builder::class, 'macro'));
    }
}
