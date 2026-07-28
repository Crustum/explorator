<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Engines\MeilisearchEngine;
use Meilisearch\Client;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use Mockery as m;
use TestApp\Model\Entity\SearchableUser;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Unit tests for MeilisearchEngine.
 */
class MeilisearchEngineTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Explorator.after_commit', false);
        Configure::write('Explorator.soft_delete', false);
        Configure::write('Explorator.prefix', '');
    }

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
    public function testMapIdsReturnsEmptyCollectionIfNoHits(): void
    {
        $engine = new MeilisearchEngine(m::mock(Client::class));
        $results = $engine->mapIdsFrom([
            'totalHits' => 0,
            'hits' => [],
        ], 'id');

        $this->assertCount(0, $results);
    }

    /**
     * @return void
     */
    public function testMapIdsReturnsCorrectValuesOfPrimaryKey(): void
    {
        $engine = new MeilisearchEngine(m::mock(Client::class));
        $results = $engine->mapIdsFrom([
            'totalHits' => 4,
            'hits' => [
                ['some_field' => 'something', 'id' => 1],
                ['some_field' => 'foo', 'id' => 2],
                ['some_field' => 'bar', 'id' => 3],
                ['some_field' => 'baz', 'id' => 4],
            ],
        ], 'id');

        $this->assertSame([1, 2, 3, 4], $results->toList());
    }

    /**
     * @return void
     */
    public function testReturnsPrimaryKeysWhenCustomArrayOrderPresent(): void
    {
        $index = m::mock(Indexes::class);
        $index->shouldReceive('rawSearch')->once()->andReturn([
            'hits' => [
                ['custom_key' => 'a', 'id' => 1],
                ['custom_key' => 'b', 'id' => 2],
            ],
        ]);

        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('users')->andReturn($index);

        $engine = new MeilisearchEngine($client);
        $table = new class ([
            'alias' => 'Users',
            'table' => 'users',
        ]) extends Table {
            /**
             * @return string
             */
            public function getExploratorKeyName(): string
            {
                return 'custom_key';
            }

            /**
             * @return string
             */
            public function searchableAs(): string
            {
                return 'users';
            }

            /**
             * @return \Crustum\Explorator\Engines\Engine
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_meili_unit_engine'];
            }
        };
        $GLOBALS['explorator_meili_unit_engine'] = $engine;

        $keys = (new Builder($table, 'q'))->keys();
        $this->assertSame(['a', 'b'], $keys->toList());
        unset($GLOBALS['explorator_meili_unit_engine']);
    }

    /**
     * @return void
     */
    public function testMapCorrectlyMapsResultsToModels(): void
    {
        $entity = new SearchableUser(['id' => 1, 'name' => 'test', 'email' => 't@example.com']);
        $entity->setNew(false);

        $table = m::mock(SearchableUsersTable::class)->makePartial();
        $table->shouldReceive('getExploratorKeyName')->andReturn('id');
        $table->shouldReceive('getExploratorModelsByIds')->andReturn(collection([$entity]));

        $engine = new MeilisearchEngine(m::mock(Client::class));
        $results = $engine->map(new Builder($table, 'q'), [
            'totalHits' => 1,
            'hits' => [
                ['id' => 1, '_rankingScore' => 0.86],
            ],
        ]);

        $this->assertCount(1, $results);
        $first = collection($results)->first();
        $this->assertSame(1, $first->id);
        $this->assertSame('test', $first->name);
        $this->assertSame(['_rankingScore' => 0.86], $first->exploratorMetadata());
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

        $engine = new MeilisearchEngine(m::mock(Client::class));
        $results = $engine->map(new Builder($table, 'q'), [
            'totalHits' => 4,
            'hits' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 4],
                ['id' => 3],
            ],
        ]);

        $this->assertSame([1, 2, 4, 3], collection($results)->extract('id')->toList());
    }

    /**
     * @return void
     */
    public function testLazyMapCorrectlyMapsResultsToModels(): void
    {
        $entity = new SearchableUser(['id' => 1, 'name' => 'test', 'email' => 't@example.com']);
        $entity->setNew(false);

        $table = m::mock(SearchableUsersTable::class)->makePartial();
        $table->shouldReceive('getExploratorKeyName')->andReturn('id');
        $table->shouldReceive('getExploratorModelsByIds')->andReturn(collection([$entity]));

        $engine = new MeilisearchEngine(m::mock(Client::class));
        $results = $engine->lazyMap(new Builder($table, 'q'), [
            'totalHits' => 1,
            'hits' => [
                ['id' => 1, '_rankingScore' => 0.86],
            ],
        ]);

        $this->assertCount(1, $results);
        $first = $results->first();
        $this->assertSame('test', $first->name);
        $this->assertSame(['_rankingScore' => 0.86], $first->exploratorMetadata());
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

        $engine = new MeilisearchEngine(m::mock(Client::class));
        $results = $engine->lazyMap(new Builder($table, 'q'), [
            'totalHits' => 4,
            'hits' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 4],
                ['id' => 3],
            ],
        ]);

        $this->assertSame([1, 2, 4, 3], $results->extract('id')->toList());
    }

    /**
     * @return void
     */
    public function testEngineForwardsCallsToMeilisearchClient(): void
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('testMethodOnClient')->once()->andReturn('meilisearch');

        $engine = new MeilisearchEngine($client);
        $this->assertSame('meilisearch', $engine->testMethodOnClient());
    }

    /**
     * @return void
     */
    public function testUpdatingEmptyCollectionDoesNothing(): void
    {
        $client = m::mock(Client::class);
        $client->shouldNotReceive('index');

        (new MeilisearchEngine($client))->update([]);
        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function testEngineReturnsHitsEntryFromSearchResponse(): void
    {
        $this->assertSame(3, (new MeilisearchEngine(m::mock(Client::class)))->getTotalCount([
            'totalHits' => 3,
        ]));
    }

    /**
     * @return void
     */
    public function testUpdateIndexSettingsWithEmbedders(): void
    {
        $client = m::mock(Client::class);
        $index = m::mock(Indexes::class);
        $client->shouldReceive('index')->with('test_index')->once()->andReturn($index);
        $index->shouldReceive('updateSettings')->with(['searchableAttributes' => ['title']])->once();
        $index->shouldReceive('updateEmbedders')->with(['default' => ['source' => 'openAi']])->once();

        (new MeilisearchEngine($client))->updateIndexSettings('test_index', [
            'searchableAttributes' => ['title'],
            'embedders' => ['default' => ['source' => 'openAi']],
        ]);
    }

    /**
     * @return void
     */
    public function testDeleteAllIndexesOnlyDeletesIndexesWithExploratorPrefix(): void
    {
        Configure::write('Explorator.prefix', 'app_');

        $client = m::mock(Client::class);
        $indexesResults = m::mock(IndexesResults::class);
        $client->shouldReceive('getIndexes')->andReturn($indexesResults);

        $otherIndex = m::mock(Indexes::class);
        $otherIndex->shouldReceive('getUid')->andReturn('users');
        $otherIndex->shouldNotReceive('delete');

        $prefixedIndex = m::mock(Indexes::class);
        $prefixedIndex->shouldReceive('getUid')->andReturn('app_users');
        $prefixedIndex->shouldReceive('delete')->once()->andReturn([]);

        $indexesResults->shouldReceive('getResults')->zeroOrMoreTimes()->andReturn([
            $otherIndex,
            $prefixedIndex,
        ]);

        (new MeilisearchEngine($client))->deleteAllIndexes();
    }
}
