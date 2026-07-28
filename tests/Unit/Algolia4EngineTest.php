<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\Algolia4Engine;
use Mockery as m;
use TestApp\Model\Entity\SearchableUser;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Unit tests for Algolia4Engine.
 */
class Algolia4EngineTest extends TestCase
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
    public function testSearchDelegatesToClient(): void
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('searchSingleIndex')
            ->once()
            ->with('users', m::type('array'))
            ->andReturn(['hits' => [['objectID' => '1']], 'nbHits' => 1]);

        $engine = new Algolia4Engine($client);
        $table = new Table(['alias' => 'Users', 'table' => 'users']);
        $builder = new Builder($table, 'sample');
        $builder->index = 'users';

        $results = $engine->search($builder);
        $this->assertSame(1, $engine->getTotalCount($results));
        $this->assertSame(['1'], $engine->mapIds($results)->toList());
    }

    /**
     * @return void
     */
    public function testMapMethodRespectsOrder(): void
    {
        $entities = [];
        foreach ([1, 2, 3, 4] as $id) {
            $entity = new SearchableUser(['id' => $id, 'name' => 'U' . $id, 'email' => $id . '@example.com']);
            $entity->setNew(false);
            $entities[] = $entity;
        }

        $table = m::mock(SearchableUsersTable::class)->makePartial();
        $table->shouldReceive('getExploratorKeyName')->andReturn('id');
        $table->shouldReceive('getExploratorModelsByIds')->andReturn(collection($entities));

        $engine = new Algolia4Engine(m::mock(SearchClient::class));
        $results = $engine->map(new Builder($table, 'q'), [
            'nbHits' => 4,
            'hits' => [
                ['objectID' => 1, 'id' => 1],
                ['objectID' => 2, 'id' => 2],
                ['objectID' => 4, 'id' => 4],
                ['objectID' => 3, 'id' => 3],
            ],
        ]);

        $this->assertSame([1, 2, 4, 3], collection($results)->extract('id')->toList());
    }

    /**
     * @return void
     */
    public function testLazyMapMethodRespectsOrder(): void
    {
        $entities = [];
        foreach ([1, 2, 3, 4] as $id) {
            $entity = new SearchableUser(['id' => $id, 'name' => 'U' . $id, 'email' => $id . '@example.com']);
            $entity->setNew(false);
            $entities[] = $entity;
        }

        $table = m::mock(SearchableUsersTable::class)->makePartial();
        $table->shouldReceive('getExploratorKeyName')->andReturn('id');
        $table->shouldReceive('getExploratorModelsByIds')->andReturn(collection($entities));

        $engine = new Algolia4Engine(m::mock(SearchClient::class));
        $results = $engine->lazyMap(new Builder($table, 'q'), [
            'nbHits' => 4,
            'hits' => [
                ['objectID' => 1, 'id' => 1],
                ['objectID' => 2, 'id' => 2],
                ['objectID' => 4, 'id' => 4],
                ['objectID' => 3, 'id' => 3],
            ],
        ]);

        $this->assertSame([1, 2, 4, 3], $results->extract('id')->toList());
    }
}
