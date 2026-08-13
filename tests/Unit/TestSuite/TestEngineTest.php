<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit\TestSuite;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Crustum\Explorator\Builder;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\SearchableIndexer;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Crustum\Explorator\TestSuite\TestEngine;
use Crustum\Explorator\TestSuite\TestIndex;
use PHPUnit\Framework\Attributes\UsesClass;
use TestApp\Model\Entity\SearchableUser;

/**
 * TestEngine capture API (Broadcasting TestBroadcasterTest analogue).
 */
#[UsesClass(TestEngine::class)]
class TestEngineTest extends FeatureTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Explorator.queue', false);
        Configure::write('Explorator.driver', 'test');
        TestEngine::clearOperations();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        TestEngine::clearOperations();
        parent::tearDown();
    }

    /**
     * @param int $id Entity id
     * @param string $name Name
     * @param string $source Entity source / table alias
     * @return \TestApp\Model\Entity\SearchableUser
     */
    protected function makeUser(int $id = 1, string $name = 'Ada', string $source = 'SearchableUsers'): SearchableUser
    {
        $entity = new SearchableUser([
            'id' => $id,
            'name' => $name,
            'email' => 'ada@example.com',
            'age' => 30,
            'created' => new DateTime(),
        ]);
        $entity->setSource($source);
        $entity->setNew(false);

        return $entity;
    }

    /**
     * @return void
     */
    public function testCreateTestDriverResolvesTestEngine(): void
    {
        $engine = (new EngineManager())->engine();

        $this->assertInstanceOf(TestEngine::class, $engine);
        $this->assertInstanceOf(TestEngine::class, (new EngineManager())->engine('test'));
    }

    /**
     * @return void
     */
    public function testUpdateCapturesOperation(): void
    {
        $entity = $this->makeUser(123, 'Grace');
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$entity]);

        $operations = TestEngine::getOperations();

        $this->assertCount(1, $operations);
        $this->assertSame('update', $operations[0]['operation']);
        $this->assertSame('SearchableUsers', $operations[0]['table']);
        $this->assertSame([123], $operations[0]['keys']);
        $this->assertSame('Grace', $operations[0]['payloads'][0]['name']);
        $this->assertSame('test', $operations[0]['engine']);
    }

    /**
     * @return void
     */
    public function testDeleteCapturesOperation(): void
    {
        $entity = $this->makeUser(4);
        (new SearchableIndexer(new EngineManager()))->removeFromSearchSync([$entity]);

        $operations = TestEngine::getOperations();

        $this->assertCount(1, $operations);
        $this->assertSame('delete', $operations[0]['operation']);
        $this->assertSame([4], $operations[0]['keys']);
        $this->assertSame([], $operations[0]['payloads']);
    }

    /**
     * @return void
     */
    public function testMultipleOperationsAreCaptured(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1)]);
        $indexer->makeSearchableSync([$this->makeUser(2)]);
        $indexer->removeFromSearchSync([$this->makeUser(3)]);

        $this->assertCount(3, TestEngine::getOperations());
    }

    /**
     * @return void
     */
    public function testClearOperations(): void
    {
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$this->makeUser(1)]);

        $this->assertCount(1, TestEngine::getOperations());

        TestEngine::clearOperations();

        $this->assertCount(0, TestEngine::getOperations());
    }

    /**
     * @return void
     */
    public function testGetOperationsByType(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1)]);
        $indexer->removeFromSearchSync([$this->makeUser(2)]);
        $indexer->makeSearchableSync([$this->makeUser(3)]);

        $this->assertCount(2, TestEngine::getOperationsByType('update'));
        $this->assertCount(1, TestEngine::getOperationsByType('delete'));
    }

    /**
     * @return void
     */
    public function testGetOperationsForTable(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1, 'A', 'SearchableUsers')]);
        $indexer->makeSearchableSync([$this->makeUser(2, 'B', 'Chirps')]);
        $indexer->makeSearchableSync([$this->makeUser(3, 'C', 'SearchableUsers')]);

        $this->assertCount(2, TestEngine::getOperationsForTable('SearchableUsers'));
        $this->assertCount(1, TestEngine::getOperationsForTable('Chirps'));
    }

    /**
     * @return void
     */
    public function testGetUpdatesAndDeletesForTable(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1)]);
        $indexer->removeFromSearchSync([$this->makeUser(2)]);
        $indexer->makeSearchableSync([$this->makeUser(3)]);

        $this->assertCount(2, TestEngine::getUpdatesForTable('SearchableUsers'));
        $this->assertCount(1, TestEngine::getDeletesForTable('SearchableUsers'));
    }

    /**
     * @return void
     */
    public function testFlushCapturesOperation(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        (new EngineManager())->engine()->flush($table);

        $operations = TestEngine::getOperations();

        $this->assertCount(1, $operations);
        $this->assertSame('flush', $operations[0]['operation']);
        $this->assertSame('SearchableUsers', $operations[0]['table']);
    }

    /**
     * @return void
     */
    public function testSearchAndPaginateAreCaptured(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        $builder = (new Builder($table, 'example'))->where('age', '>', 20);
        $engine = (new EngineManager())->engine();
        $engine->search($builder);
        $engine->paginate($builder, 15, 1);

        $searches = TestEngine::getSearches();

        $this->assertCount(2, $searches);
        $this->assertSame('search', $searches[0]['operation']);
        $this->assertSame('paginate', $searches[1]['operation']);
        $this->assertSame('example', $searches[0]['query']);
        $this->assertSame('SearchableUsers', $searches[0]['table']);
        $this->assertNotEmpty($searches[0]['wheres']);
    }

    /**
     * @return void
     */
    public function testGetWritesExcludesSearches(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        $engine = (new EngineManager())->engine();
        $engine->search(new Builder($table, 'q'));
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$this->makeUser(1)]);
        $engine->flush($table);

        $this->assertCount(2, TestEngine::getWrites());
        $this->assertCount(1, TestEngine::getSearches());
        $this->assertCount(3, TestEngine::getOperations());
    }

    /**
     * setSearchResults stubs the raw hits returned by search()/paginate().
     *
     * @return void
     */
    public function testSetSearchResultsStubsRawHits(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        $engine = (new EngineManager())->engine();

        TestEngine::setSearchResults([
            ['id' => 7, 'name' => 'Ada', '_rankingScore' => 0.9],
            ['id' => 8, 'name' => 'Grace', '_rankingScore' => 0.8],
        ]);

        $result = $engine->search(new Builder($table, 'ada'));

        $this->assertSame([
            ['id' => 7, 'name' => 'Ada', '_rankingScore' => 0.9],
            ['id' => 8, 'name' => 'Grace', '_rankingScore' => 0.8],
        ], $result);
        $this->assertCount(1, TestEngine::getSearches());
    }

    /**
     * clearSearchResults resets the stub so search() returns an empty list.
     *
     * @return void
     */
    public function testClearSearchResultsResetsStub(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        $engine = (new EngineManager())->engine();

        TestEngine::setSearchResults([['id' => 1, 'name' => 'Ada']]);
        TestEngine::clearSearchResults();

        $this->assertSame([], $engine->search(new Builder($table, 'ada')));
    }

    /**
     * A Builder callback (engine-specific index) is executed against the stub
     * results, so searchable tables with a callback run end-to-end.
     *
     * @return void
     */
    public function testSearchInvokesBuilderCallbackWithStubbedIndex(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        $engine = (new EngineManager())->engine();

        TestEngine::setSearchResults([
            ['id' => 7, 'name' => 'Ada'],
            ['id' => 8, 'name' => 'Grace'],
        ]);

        $builder = new Builder($table, 'ada', function ($index, string $query, array $params): array {
            $this->assertInstanceOf(TestIndex::class, $index);
            $this->assertSame('ada', $query);

            return $index->rawSearch($query, $params);
        });

        $result = $engine->search($builder);

        $this->assertSame([
            ['id' => 7, 'name' => 'Ada'],
            ['id' => 8, 'name' => 'Grace'],
        ], $result['hits']);
    }

    /**
     * @return void
     */
    public function testEmptyUpdateDoesNotCapture(): void
    {
        (new EngineManager())->engine()->update([]);

        $this->assertSame([], TestEngine::getOperations());
    }

    /**
     * @return void
     */
    public function testFlushAcceptsPlainTable(): void
    {
        $table = new Table(['alias' => 'DummyIndex']);
        (new EngineManager())->engine()->flush($table);

        $operations = TestEngine::getOperations();

        $this->assertCount(1, $operations);
        $this->assertSame('DummyIndex', $operations[0]['table']);
    }

    /**
     * @return void
     */
    public function testBatchUpdateCapturesMultipleKeysAndPayloads(): void
    {
        $entities = [
            $this->makeUser(1, 'One'),
            $this->makeUser(2, 'Two'),
        ];
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync($entities);

        $operations = TestEngine::getOperations();

        $this->assertCount(1, $operations);
        $this->assertSame([1, 2], $operations[0]['keys']);
        $this->assertCount(2, $operations[0]['payloads']);
        $this->assertSame('One', $operations[0]['payloads'][0]['name']);
        $this->assertSame('Two', $operations[0]['payloads'][1]['name']);
    }
}
