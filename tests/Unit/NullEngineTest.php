<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\NullEngine;

/**
 * Unit tests for NullEngine.
 */
class NullEngineTest extends TestCase
{
    /**
     * @return void
     */
    public function testSearchAndMapAreEmpty(): void
    {
        $engine = new NullEngine();
        $builder = new Builder(new Table(['alias' => 'Dummy']));

        $this->assertSame([], $engine->search($builder));
        $this->assertSame([], $engine->paginate($builder, 15, 1));
        $this->assertSame(0, $engine->getTotalCount([]));
        $this->assertCount(0, $engine->mapIds([]));
        $this->assertCount(0, $engine->map($builder, []));
        $this->assertCount(0, $engine->lazyMap($builder, []));
        $this->assertSame([], $engine->createIndex('x'));
        $this->assertSame([], $engine->deleteIndex('x'));

        $engine->update([]);
        $engine->delete([]);
        $engine->flush(new Table(['alias' => 'Dummy']));
    }
}
