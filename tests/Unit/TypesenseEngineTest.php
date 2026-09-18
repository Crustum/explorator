<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Cache\Cache;
use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\ORM\Locator\TableLocator;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;
use Crustum\Ai\Ai;
use Crustum\Ai\AiManager;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Providers\OpenAiProvider;
use Crustum\Ai\Registry\ProviderRegistry;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engine\TypesenseEngine;
use Crustum\Explorator\Exception\ExploratorException;
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
use Typesense\Exceptions\RequestMalformed;
use Typesense\MultiSearch;

/**
 * Unit tests for TypesenseEngine.
 */
#[AllowMockObjectsWithoutExpectations]
class TypesenseEngineTest extends TestCase
{
    /**
     * @var \Crustum\Explorator\Engine\TypesenseEngine&\PHPUnit\Framework\MockObject\MockObject
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
        $this->registerTable($this->engine, 'SearchableUsers');

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
        $this->registerTable($this->engine, 'SearchableUsers');

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

    /**
     * Register a SearchableUsers table on an engine's own table locator.
     *
     * @param \Crustum\Explorator\Engine\TypesenseEngine $engine Engine
     * @param string $alias Table alias
     * @return void
     */
    protected function registerTable(TypesenseEngine $engine, string $alias): void
    {
        $locator = new TableLocator();
        $locator->set($alias, new SearchableUsersTable([
            'alias' => $alias,
            'table' => 'searchable_users',
        ]));
        $engine->setTableLocator($locator);
    }

    /**
     * Create an engine with embedding settings for the SearchableUsers table.
     *
     * @param array<string, mixed> $embedding Embedding settings
     * @param string $queryBy Default query_by search parameter
     * @return \Crustum\Explorator\Engine\TypesenseEngine
     */
    protected function semanticEngine(array $embedding, string $queryBy = 'name'): TypesenseEngine
    {
        Configure::write(
            'Explorator.typesense.model-settings.' . SearchableUsersTable::class,
            ['search-parameters' => ['query_by' => $queryBy]],
        );

        return new TypesenseEngine($this->createMock(TypesenseClient::class), 1000, false, [
            'model-settings' => [
                SearchableUsersTable::class => [
                    'embedding' => $embedding,
                ],
            ],
        ]);
    }

    /**
     * Create a partially mocked engine whose client performs multi-searches.
     *
     * @param \Typesense\MultiSearch $multiSearch Multi-search mock
     * @return \Crustum\Explorator\Engine\TypesenseEngine&\PHPUnit\Framework\MockObject\MockObject
     */
    protected function multiSearchEngine(MultiSearch $multiSearch): TypesenseEngine
    {
        $client = $this->createMock(TypesenseClient::class);
        $client->method('getMultiSearch')->willReturn($multiSearch);

        $engine = $this->getMockBuilder(TypesenseEngine::class)
            ->setConstructorArgs([$client, 1000, false, []])
            ->onlyMethods(['getOrCreateCollectionFromModel', 'buildSearchParameters'])
            ->getMock();

        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->method('getDocuments')->willReturn($documents);
        $documents->expects($this->never())->method('search');

        $engine->method('getOrCreateCollectionFromModel')->willReturn($collection);

        return $engine;
    }

    /**
     * Fake embeddings generation via the Crustum AI package.
     *
     * @param array<int, array<int, array<int, float>>> $responses Embedding responses
     * @return void
     */
    protected function fakeEmbeddings(array $responses): void
    {
        if (!class_exists(Embeddings::class)) {
            $this->markTestSkipped('crustum/cakephp-ai is required to test semantic/hybrid embeddings.');
        }

        Configure::write('Ai.providers.openai', [
            'className' => OpenAiProvider::class,
            'apiKey' => 'test',
        ]);
        Configure::write('Ai.default_for_embeddings', 'openai');
        Configure::write('Ai.caching.embeddings.store', '_explorator_fake_embeddings');
        Configure::write('Ai.caching.embeddings.seconds', 60);

        if (!Cache::getConfig('_explorator_fake_embeddings')) {
            Cache::setConfig('_explorator_fake_embeddings', ['className' => 'Array']);
        }

        Ai::setManager(new AiManager(new ProviderRegistry()));
        Embeddings::fake($responses);
    }

    /**
     * @return void
     */
    public function testUpdateAddsPrecomputedAndGeneratedEmbeddingsToDocuments(): void
    {
        $this->fakeEmbeddings([[[0.3, 0.4]]]);

        $precomputed = new class (['id' => 10, 'name' => 'Precomputed']) extends SearchableUser {
            /**
             * @return array<string, mixed>
             */
            public function toSearchableArray(): array
            {
                return ['id' => 10, 'name' => 'Precomputed'];
            }

            /**
             * @return float[]
             */
            public function toSearchableEmbedding(): array
            {
                return [0.1, 0.2];
            }
        };
        $precomputed->setSource('SearchableUsers');

        $generated = new class (['id' => 20, 'name' => 'Generate this']) extends SearchableUser {
            /**
             * @return array<string, mixed>
             */
            public function toSearchableArray(): array
            {
                return ['id' => 20, 'name' => 'Generate this'];
            }

            /**
             * @return string
             */
            public function toSearchableEmbedding(): string
            {
                return 'Generate this';
            }
        };
        $generated->setSource('SearchableUsers');

        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);
        $documents->expects($this->once())
            ->method('import')
            ->with(
                [
                    ['id' => 10, 'name' => 'Precomputed', 'embedding' => [0.1, 0.2]],
                    ['id' => 20, 'name' => 'Generate this', 'embedding' => [0.3, 0.4]],
                ],
                ['action' => 'upsert'],
            )
            ->willReturn([
                ['success' => true],
                ['success' => true],
            ]);

        $client = $this->createMock(TypesenseClient::class);
        $collections = $this->createMock(Collections::class);
        $client->method('getCollections')->willReturn($collections);

        $engine = $this->getMockBuilder(TypesenseEngine::class)
            ->setConstructorArgs([$client, 1000, false, [
                'model-settings' => [
                    SearchableUsersTable::class => [
                        'embedding' => [
                            'attribute' => 'embedding',
                            'dimensions' => 2,
                            'provider' => 'openai',
                            'model' => 'text-embedding-test',
                        ],
                    ],
                ],
            ]])
            ->onlyMethods(['getOrCreateCollectionFromModel'])
            ->getMock();
        $this->registerTable($engine, 'SearchableUsers');
        $engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        $engine->update([$precomputed, $generated]);
    }

    /**
     * @return void
     */
    public function testUpdateDoesNotGenerateEmbeddingsWhenUsingNativeEmbeddings(): void
    {
        $this->fakeEmbeddings([]);

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

        $engine = $this->getMockBuilder(TypesenseEngine::class)
            ->setConstructorArgs([$this->createMock(TypesenseClient::class), 1000, false, [
                'model-settings' => [
                    SearchableUsersTable::class => [
                        'embedding' => [
                            'attribute' => 'embedding',
                            'driver' => 'typesense',
                        ],
                    ],
                ],
            ]])
            ->onlyMethods(['getOrCreateCollectionFromModel'])
            ->getMock();
        $this->registerTable($engine, 'SearchableUsers');
        $engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        $engine->update([$entity]);
    }

    /**
     * @return void
     */
    public function testSemanticSearchGeneratesAQueryVector(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'dimensions' => 2,
        ]);

        $this->fakeEmbeddings([[[0.25, 0.75]]]);

        $builder = $this->createBuilder('conceptual query')->semantic(minSimilarity: 0.7);

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('*', $parameters['q']);
        $this->assertSame('name', $parameters['query_by']);
        $this->assertSame('embedding:([0.25, 0.75], distance_threshold: 0.3)', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
        $this->assertArrayNotHasKey('vector', $parameters);
    }

    /**
     * @return void
     */
    public function testHybridSearchAcceptsAPrecomputedQueryVectorAndNormalizesWeights(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'dimensions' => 2,
        ]);

        $this->fakeEmbeddings([]);

        $builder = $this->createBuilder('combined query')
            ->options(['vector' => [0.4, 0.6]])
            ->hybrid(textWeight: 1, semanticWeight: 2);

        $parameters = $engine->buildSearchParameters($builder, 2, 5);

        $this->assertSame('combined query', $parameters['q']);
        $this->assertSame('name', $parameters['query_by']);
        $this->assertSame('embedding:([0.4, 0.6], alpha: ' . (2 / 3) . ')', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
        $this->assertArrayNotHasKey('vector', $parameters);
    }

    /**
     * @return void
     */
    public function testSemanticSearchWithNativeEmbeddingsQueriesTheEmbeddingField(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ]);

        $this->fakeEmbeddings([]);

        $builder = $this->createBuilder('conceptual query')->semantic();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('conceptual query', $parameters['q']);
        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertFalse($parameters['prefix']);
        $this->assertArrayNotHasKey('vector_query', $parameters);
        $this->assertSame('embedding', $parameters['exclude_fields']);
    }

    /**
     * @return void
     */
    public function testSemanticSearchWithNativeEmbeddingsAppliesADistanceThreshold(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ]);

        $builder = $this->createBuilder('conceptual query')->semantic(minSimilarity: 0.5);

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertSame('embedding:([], distance_threshold: 0.5)', $parameters['vector_query']);
    }

    /**
     * @return void
     */
    public function testSemanticSearchWithNativeEmbeddingsDropsPerFieldParameters(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ], 'name,description');

        $builder = $this->createBuilder('conceptual query')
            ->options(['query_by_weights' => '2,1', 'num_typos' => '2,1', 'infix' => 'off'])
            ->semantic();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertFalse($parameters['prefix']);
        $this->assertArrayNotHasKey('query_by_weights', $parameters);
        $this->assertArrayNotHasKey('num_typos', $parameters);
        $this->assertSame('off', $parameters['infix']);
    }

    /**
     * @return void
     */
    public function testHybridSearchWithNativeEmbeddingsAppendsTheEmbeddingFieldToQueryBy(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ]);

        $builder = $this->createBuilder('combined query')->hybrid();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('combined query', $parameters['q']);
        $this->assertSame('name,embedding', $parameters['query_by']);
        $this->assertSame('true,false', $parameters['prefix']);
        $this->assertSame('embedding:([], alpha: 0.5)', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
    }

    /**
     * @return void
     */
    public function testHybridSearchWithNativeEmbeddingsExtendsPerFieldParameters(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ], 'name,description');

        $builder = $this->createBuilder('combined query')
            ->options(['query_by_weights' => '2,1', 'num_typos' => '2,1', 'prefix' => 'true,false', 'infix' => 'off'])
            ->hybrid();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('name,description,embedding', $parameters['query_by']);
        $this->assertSame('2,1,0', $parameters['query_by_weights']);
        $this->assertSame('2,1,0', $parameters['num_typos']);
        $this->assertSame('true,false,false', $parameters['prefix']);
        $this->assertSame('off', $parameters['infix']);
    }

    /**
     * @return void
     */
    public function testHybridSearchRequiresAKeywordFieldInQueryBy(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ], '');

        $builder = $this->createBuilder('combined query')->hybrid();

        $this->expectException(ExploratorException::class);
        $this->expectExceptionMessage('Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    /**
     * @return void
     */
    public function testSemanticSearchCannotBeCombinedWithACustomVectorQueryOption(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'dimensions' => 2,
        ]);

        $builder = $this->createBuilder('conceptual query')
            ->options(['vector_query' => 'embedding:([], k: 10)'])
            ->semantic();

        $this->expectException(ExploratorException::class);
        $this->expectExceptionMessage('Typesense semantic and hybrid searches cannot be combined with a custom [vector_query] option.');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    /**
     * @return void
     */
    public function testSemanticSearchRequiresEmbeddingSettings(): void
    {
        Configure::delete('Explorator.typesense.model-settings.' . SearchableUsersTable::class);

        $engine = new TypesenseEngine($this->createMock(TypesenseClient::class), 1000);

        $builder = $this->createBuilder('conceptual query')->semantic();

        $this->expectException(ExploratorException::class);
        $this->expectExceptionMessage('No Typesense embedding settings have been configured for [' . SearchableUsersTable::class . '].');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    /**
     * @return void
     */
    public function testSearchesWithVectorQueriesUseTheMultiSearchEndpoint(): void
    {
        $multiSearch = $this->createMock(MultiSearch::class);
        $engine = $this->multiSearchEngine($multiSearch);

        $engine->expects($this->once())
            ->method('buildSearchParameters')
            ->willReturn([
                'q' => '*',
                'query_by' => 'name',
                'vector_query' => 'embedding:([0.1, 0.2])',
            ]);

        $multiSearch->expects($this->once())
            ->method('perform')
            ->with([
                'searches' => [
                    [
                        'q' => '*',
                        'query_by' => 'name',
                        'vector_query' => 'embedding:([0.1, 0.2])',
                        'collection' => 'searchable_users',
                    ],
                ],
            ])
            ->willReturn(['results' => [['found' => 1, 'hits' => [['document' => ['id' => '1']]]]]]);

        $results = $engine->search($this->createBuilder('conceptual query'));

        $this->assertSame(1, $results['found']);
        $this->assertSame('1', $results['hits'][0]['document']['id']);
    }

    /**
     * @return void
     */
    public function testMultiSearchErrorsAreConvertedToTypesenseExceptions(): void
    {
        $multiSearch = $this->createMock(MultiSearch::class);
        $engine = $this->multiSearchEngine($multiSearch);

        $engine->method('buildSearchParameters')->willReturn([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->method('perform')->willReturn([
            'results' => [['code' => 400, 'error' => 'Query string exceeds max allowed length.']],
        ]);

        $this->expectException(RequestMalformed::class);
        $this->expectExceptionMessage('Query string exceeds max allowed length.');

        $engine->search($this->createBuilder('conceptual query'));
    }

    /**
     * @return void
     */
    public function testMultiSearchCreatesMissingCollectionsAndRetries(): void
    {
        $multiSearch = $this->createMock(MultiSearch::class);
        $engine = $this->multiSearchEngine($multiSearch);

        $engine->method('buildSearchParameters')->willReturn([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->expects($this->exactly(2))
            ->method('perform')
            ->willReturnOnConsecutiveCalls(
                ['results' => [['code' => 404, 'error' => 'Not found.']]],
                ['results' => [['found' => 0, 'hits' => []]]],
            );

        $results = $engine->search($this->createBuilder('conceptual query'));

        $this->assertSame(0, $results['found']);
    }
}
