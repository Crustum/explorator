<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\TypesenseEngine;
use Exception;
use Mockery as m;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use TestApp\Model\Entity\Chirp;
use TestApp\Model\Entity\SearchableUser;
use TestApp\Model\Table\SearchableUsersTable;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Collections;
use Typesense\Document;
use Typesense\Documents;

/**
 * Unit tests for TypesenseEngine.
 */
#[AllowMockObjectsWithoutExpectations]
class TypesenseEngineTest extends TestCase
{
    /**
     * @var \Crustum\Explorator\Engines\TypesenseEngine&\PHPUnit\Framework\MockObject\MockObject
     */
    protected MockObject $engine;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Configure::write('Explorator.soft_delete', false);
        Configure::write('Explorator.typesense.import_action', 'upsert');

        $typesenseClient = $this->createStub(TypesenseClient::class);
        $this->engine = $this->getMockBuilder(TypesenseEngine::class)
            ->setConstructorArgs([$typesenseClient, 1000, false])
            ->onlyMethods(['getOrCreateCollectionFromModel', 'buildSearchParameters'])
            ->getMock();
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
     * @param object $object Object
     * @param string $methodName Method name
     * @param array<int, mixed> $parameters Parameters
     * @return mixed
     */
    protected function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * @return void
     */
    public function testFiltersMethod(): void
    {
        $builder = m::mock(Builder::class);
        $builder->wheres = [
            ['field' => 'status', 'value' => 'active', 'operator' => '='],
            ['field' => 'age', 'value' => 25, 'operator' => '='],
        ];
        $builder->whereIns = [
            'category' => ['electronics', 'books'],
        ];
        $builder->whereNotIns = [
            'category' => ['furniture', 'phones'],
        ];

        $result = $this->invokeMethod($this->engine, 'filters', [$builder]);

        $expected = 'status:=active && age:=25 && category:=[electronics, books] && category:!=[furniture, phones]';
        $this->assertSame($expected, $result);
    }

    /**
     * @return void
     */
    public function testParseFilterValueMethod(): void
    {
        $this->assertSame('true', $this->invokeMethod($this->engine, 'parseFilterValue', [true]));
        $this->assertSame('false', $this->invokeMethod($this->engine, 'parseFilterValue', [false]));
        $this->assertSame(25, $this->invokeMethod($this->engine, 'parseFilterValue', [25]));
        $this->assertSame(3.14, $this->invokeMethod($this->engine, 'parseFilterValue', [3.14]));
        $this->assertSame('test', $this->invokeMethod($this->engine, 'parseFilterValue', ['test']));
        $this->assertSame('test "quoted"', $this->invokeMethod($this->engine, 'parseFilterValue', ['test "quoted"']));
        $this->assertSame('`special value`', $this->invokeMethod($this->engine, 'parseFilterValue', ['`special value`']));

        $nestedArray = ['a', ['b', 'c'], 'd'];
        $expectedNested = ['a', ['b', 'c'], 'd'];
        $this->assertSame($expectedNested, $this->invokeMethod($this->engine, 'parseFilterValue', [$nestedArray]));
    }

    /**
     * @return void
     */
    public function testParseWhereFilterMethod(): void
    {
        $this->assertSame('status:=active', $this->invokeMethod($this->engine, 'parseWhereFilter', ['active', 'status']));
        $this->assertSame('age:=25', $this->invokeMethod($this->engine, 'parseWhereFilter', ['25', 'age']));
        $this->assertSame('tags:tag1tag2tag3', $this->invokeMethod($this->engine, 'parseWhereFilter', [['tag1', 'tag2', 'tag3'], 'tags']));
    }

    /**
     * @return void
     */
    public function testParseWhereInFilterMethod(): void
    {
        $this->assertSame(
            'category:=[electronics, books]',
            $this->invokeMethod($this->engine, 'parseWhereInFilter', [['electronics', 'books'], 'category']),
        );
        $this->assertSame(
            'id:=[1, 2, 3]',
            $this->invokeMethod($this->engine, 'parseWhereInFilter', [[1, 2, 3], 'id']),
        );
    }

    /**
     * @return void
     */
    public function testParseWhereNotInFilterMethod(): void
    {
        $this->assertSame(
            'category:!=[electronics, books]',
            $this->invokeMethod($this->engine, 'parseWhereNotInFilter', [['electronics', 'books'], 'category']),
        );
        $this->assertSame(
            'id:!=[1, 2, 3]',
            $this->invokeMethod($this->engine, 'parseWhereNotInFilter', [[1, 2, 3], 'id']),
        );
    }

    /**
     * @return void
     */
    public function testUpdateMethod(): void
    {
        $entity = new class (['id' => 1, 'name' => 'Model 1']) extends SearchableUser {
            /**
             * @return array<string, mixed>
             */
            public function toSearchableArray(): array
            {
                return ['id' => 1, 'name' => 'Model 1'];
            }
        };
        $entity->setSource('SearchableUsers');

        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);
        $documents->expects($this->once())
            ->method('import')
            ->with(
                [['id' => 1, 'name' => 'Model 1']],
                ['action' => 'upsert'],
            )
            ->willReturn([[
                'success' => true,
            ]]);

        $this->engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        $this->engine->update([$entity]);
    }

    /**
     * @return void
     */
    public function testUpdateMethodWithEmplaceAction(): void
    {
        Configure::write('Explorator.typesense.import_action', 'emplace');

        $entity = new class (['id' => 1, 'name' => 'Model 1']) extends SearchableUser {
            /**
             * @return array<string, mixed>
             */
            public function toSearchableArray(): array
            {
                return ['id' => 1, 'name' => 'Model 1'];
            }
        };
        $entity->setSource('SearchableUsers');

        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);
        $documents->expects($this->once())
            ->method('import')
            ->with(
                [['id' => 1, 'name' => 'Model 1']],
                ['action' => 'emplace'],
            )
            ->willReturn([[
                'success' => true,
            ]]);

        $this->engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        $this->engine->update([$entity]);
    }

    /**
     * @return void
     */
    public function testDeleteMethod(): void
    {
        $entity = new Chirp(['explorator_id' => 1, 'id' => 1]);
        $entity->setSource('Chirps');

        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $document = $this->createMock(Document::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);
        $documents->expects($this->once())
            ->method('offsetGet')
            ->with('1')
            ->willReturn($document);
        $document->expects($this->once())
            ->method('retrieve');
        $document->expects($this->once())
            ->method('delete')
            ->willReturn([]);

        $this->engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        $this->engine->delete([$entity]);
    }

    /**
     * @return void
     */
    public function testSearchMethod(): void
    {
        $builder = $this->createBuilder('zonda');

        $this->engine->expects($this->once())
            ->method('buildSearchParameters')
            ->with($builder, 1, $builder->limit ?? 250)
            ->willReturn([
                'q' => 'zonda',
                'query_by' => 'id',
                'filter_by' => '',
                'per_page' => 10,
                'page' => 1,
                'highlight_start_tag' => '<mark>',
                'highlight_end_tag' => '</mark>',
                'snippet_threshold' => 30,
                'exhaustive_search' => false,
                'use_cache' => false,
                'cache_ttl' => 60,
                'prioritize_exact_match' => true,
                'enable_overrides' => true,
                'highlight_affix_num_tokens' => 4,
            ]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testPaginateMethod(): void
    {
        $builder = $this->createBuilder('zonda');

        $this->engine->expects($this->once())
            ->method('buildSearchParameters')
            ->with($builder, 2, 10)
            ->willReturn([
                'q' => 'zonda',
                'query_by' => 'id',
                'filter_by' => '',
                'per_page' => 10,
                'page' => 2,
                'highlight_start_tag' => '<mark>',
                'highlight_end_tag' => '</mark>',
                'snippet_threshold' => 30,
                'exhaustive_search' => false,
                'use_cache' => false,
                'cache_ttl' => 60,
                'prioritize_exact_match' => true,
                'enable_overrides' => true,
                'highlight_affix_num_tokens' => 4,
            ]);

        $this->engine->paginate($builder, 10, 2);
    }

    /**
     * @return void
     */
    public function testMapIdsMethod(): void
    {
        $results = [
            'hits' => [
                ['document' => ['id' => 1]],
                ['document' => ['id' => 2]],
                ['document' => ['id' => 3]],
            ],
        ];

        $mappedIds = $this->engine->mapIds($results);

        $this->assertInstanceOf(CollectionInterface::class, $mappedIds);
        $this->assertSame([1, 2, 3], $mappedIds->toList());
    }

    /**
     * @return void
     */
    public function testGetTotalCountMethod(): void
    {
        $resultsWithFound = ['found' => 5];
        $resultsWithoutFound = ['hits' => []];

        $this->assertSame(5, $this->engine->getTotalCount($resultsWithFound));
        $this->assertSame(0, $this->engine->getTotalCount($resultsWithoutFound));
    }

    /**
     * @return void
     */
    public function testFlushMethod(): void
    {
        $table = $this->createMock(Table::class);
        $collection = $this->createMock(TypesenseCollection::class);

        $this->engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->with($table)
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('delete');

        $this->engine->flush($table);
    }

    /**
     * @return void
     */
    public function testCreateIndexMethodThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Typesense indexes are created automatically upon adding objects.');

        $this->engine->createIndex('test_index');
    }

    /**
     * @return void
     */
    public function testSetSearchParamsMethod(): void
    {
        $builder = $this->createBuilder('zonda');
        $builder->options(['query_by' => 'id']);

        $this->engine->expects($this->once())
            ->method('buildSearchParameters')
            ->with($builder, 1, $builder->limit ?? 250)
            ->willReturn([
                'q' => 'zonda',
                'query_by' => 'id',
                'filter_by' => '',
                'per_page' => 10,
                'page' => 1,
                'highlight_start_tag' => '<mark>',
                'highlight_end_tag' => '</mark>',
                'snippet_threshold' => 30,
                'exhaustive_search' => false,
                'use_cache' => false,
                'cache_ttl' => 60,
                'prioritize_exact_match' => true,
                'enable_overrides' => true,
                'highlight_affix_num_tokens' => 4,
            ]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testParseOrderByMethod(): void
    {
        $orders = [
            ['column' => 'name', 'direction' => 'asc'],
            ['column' => 'created', 'direction' => 'desc'],
        ];

        $this->assertSame('name:asc,created:desc', $this->invokeMethod($this->engine, 'parseOrderBy', [$orders]));
    }

    /**
     * @return void
     */
    public function testCallPassthroughDelegatesToClient(): void
    {
        $collections = $this->createMock(Collections::class);
        $client = $this->createMock(TypesenseClient::class);
        $client->expects($this->once())
            ->method('getCollections')
            ->willReturn($collections);

        $engine = new TypesenseEngine($client);

        $this->assertSame($collections, $engine->getCollections());
    }

    /**
     * @return void
     */
    public function testBuildSearchParametersAppliesOrderBy(): void
    {
        $client = $this->createMock(TypesenseClient::class);
        $engine = new TypesenseEngine($client);

        $table = new Table(['alias' => 'Users', 'table' => 'users']);
        $builder = new Builder($table, 'query');
        $builder->orderBy('name', 'asc');

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('name:asc', $parameters['sort_by']);
    }

    /**
     * @return void
     */
    public function testCreateImportSortingDataObjectMethod(): void
    {
        $document = [
            'success' => true,
            'code' => 201,
            'document' => json_encode(['id' => '1', 'name' => 'Test'], JSON_THROW_ON_ERROR),
        ];

        $result = $this->invokeMethod($this->engine, 'createImportSortingDataObject', [$document]);

        $this->assertTrue($result->success);
        $this->assertSame(201, $result->code);
        $this->assertSame(['id' => '1', 'name' => 'Test'], $result->document);
    }

    /**
     * @return void
     */
    public function testSoftDeletedObjectsAreReturnedWithOnlyTrashedMethod(): void
    {
        $builder = new Builder($this->createBuilder()->table, 'Soft Deleted Object', softDelete: true);
        $builder->onlyTrashed();

        $this->assertTrue(array_any(
            $builder->wheres,
            static fn(array $where): bool => $where['field'] === '__soft_deleted'
                && $where['operator'] === '='
                && $where['value'] === 1,
        ));
    }

    /**
     * @return void
     */
    public function testSoftDeletedObjectsAreReturnedWithWithTrashedMethod(): void
    {
        $builder = new Builder($this->createBuilder()->table, 'Soft Deleted Object', softDelete: true);
        $builder->withTrashed();

        $this->assertFalse(array_any(
            $builder->wheres,
            static fn(array $where): bool => $where['field'] === '__soft_deleted',
        ));
    }

    /**
     * @param string $query Search query
     * @return \Crustum\Explorator\Builder
     */
    protected function createBuilder(string $query = 'zonda'): Builder
    {
        $table = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
        ]);

        return new Builder($table, $query);
    }
}
