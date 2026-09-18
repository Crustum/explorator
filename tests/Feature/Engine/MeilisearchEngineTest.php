<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Engine;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Crustum\Ai\Ai;
use Crustum\Ai\AiManager;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Providers\OpenAiProvider;
use Crustum\Ai\Registry\ProviderRegistry;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engine\MeilisearchEngine;
use Crustum\Explorator\Exception\ExploratorException;
use Crustum\Explorator\Model\Behavior\SearchableBehavior;
use Crustum\Explorator\Support\RemoveableExploratorCollection;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Meilisearch\Client as SearchClient;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;
use Mockery as m;
use TestApp\Model\Entity\Chirp;
use TestApp\Model\Entity\MeilisearchSearchableUser;
use TestApp\Model\Table\ChirpsTable;
use TestApp\Model\Table\MeilisearchSearchableUsersTable;

/**
 * Feature tests for MeilisearchEngine.
 */
class MeilisearchEngineTest extends FeatureTestCase
{
    /**
     * @var \Mockery\MockInterface&\Meilisearch\Client
     */
    protected SearchClient $client;

    /**
     * @var \Crustum\Explorator\Engine\MeilisearchEngine
     */
    protected MeilisearchEngine $engine;

    /**
     * @var \TestApp\Model\Table\MeilisearchSearchableUsersTable
     */
    protected MeilisearchSearchableUsersTable $SearchableUsers;

    /**
     * @var \TestApp\Model\Table\ChirpsTable
     */
    protected ChirpsTable $Chirps;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Configure::write('Explorator.driver', 'meilisearch-testing');
        Configure::write('Explorator.soft_delete', false);

        $this->client = m::spy(SearchClient::class);
        $this->engine = new MeilisearchEngine($this->client, (bool)Configure::read('Explorator.soft_delete'));

        $this->SearchableUsers = new MeilisearchSearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $this->SearchableUsers);

        $this->Chirps = new ChirpsTable([
            'alias' => 'Chirps',
            'table' => 'chirps',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('Chirps', $this->Chirps);

        SearchableBehavior::enableSyncingFor('SearchableUsers');
        SearchableBehavior::enableSyncingFor('Chirps');

        if (class_exists(AiManager::class)) {
            Ai::setManager(new AiManager(new ProviderRegistry()));
        }
    }

    /**
     * Fake embeddings generation via the Crustum AI package.
     *
     * @param array<int, array<int, array<int, float>>> $responses
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
    protected function tearDown(): void
    {
        unset($_ENV['user.toSearchableArray'], $_ENV['chirp.toSearchableArray']);
        m::close();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data Entity data
     * @return \TestApp\Model\Entity\MeilisearchSearchableUser
     */
    protected function createUserQuietly(array $data = []): MeilisearchSearchableUser
    {
        return $this->SearchableUsers->withoutSyncingToSearch(fn(): MeilisearchSearchableUser => $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity(array_merge([
            'name' => 'Sample',
            'email' => 'sample@example.test',
            'created' => new DateTime(),
        ], $data))));
    }

    /**
     * @param array<string, mixed> $data Entity data
     * @return \TestApp\Model\Entity\Chirp
     */
    protected function createChirpQuietly(array $data = []): Chirp
    {
        return $this->Chirps->withoutSyncingToSearch(fn(): Chirp => $this->Chirps->saveOrFail($this->Chirps->newEntity(array_merge([
            'content' => 'Hello world',
            'created' => new DateTime(),
        ], $data))));
    }

    /**
     * @return void
     */
    public function testUpdateAddsObjectsToIndex(): void
    {
        $model = $this->createUserQuietly();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->once()->with(
            [$model->toSearchableArray()],
            'id',
        );

        $this->engine->update([$model]);
    }

    /**
     * @return void
     */
    public function testDeleteRemovesObjectsToIndex(): void
    {
        $model = $this->createUserQuietly();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->once()->with([1]);

        $this->engine->delete([$model]);
    }

    /**
     * @return void
     */
    public function testDeleteRemovesObjectsToIndexWithACustomSearchKey(): void
    {
        $model = $this->createChirpQuietly(['explorator_id' => 'my-meilisearch-key.5']);

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->once()->with(['my-meilisearch-key.5']);

        $this->engine->delete([$model]);
    }

    /**
     * @return void
     */
    public function testDeleteWithRemovableExploratorCollectionUsingCustomSearchKey(): void
    {
        $model = $this->createChirpQuietly(['explorator_id' => 'my-meilisearch-key.5']);

        $collection = RemoveableExploratorCollection::fromEntities([$model]);
        $collection = unserialize(serialize($collection));

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->once()->with(['my-meilisearch-key.5']);

        $this->engine->delete($collection);
    }

    /**
     * @return void
     */
    public function testSearchSendsCorrectParametersToMeilisearch(): void
    {
        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with('mustang', [
            'filter' => 'foo=1 AND bar=2',
        ]);

        $builder = new Builder($this->SearchableUsers, 'mustang', function ($meilisearch, $query, array $options) {
            $options['filter'] = 'foo=1 AND bar=2';

            return $meilisearch->search($query, $options);
        });

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testSearchIncludesAtLeastExploratorKeyNameInAttributesToRetrieveOnBuilderOptions(): void
    {
        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with('mustang', [
            'filter' => 'foo=1 AND bar=2',
            'attributesToRetrieve' => ['id', 'foo'],
        ]);

        $builder = new Builder($this->SearchableUsers, 'mustang', function ($meilisearch, $query, array $options) {
            $options['filter'] = 'foo=1 AND bar=2';

            return $meilisearch->search($query, $options);
        });
        $builder->options = ['attributesToRetrieve' => ['foo']];

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testSubmittingACallableSearchWithSearchMethodReturnsArray(): void
    {
        $builder = new Builder(
            $this->SearchableUsers,
            $query = 'mustang',
            function ($meilisearch, $query, array $options) {
                $options['filter'] = 'foo=1';

                return $meilisearch->search($query, $options);
            },
        );

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with($query, ['filter' => 'foo=1'])->andReturn(new SearchResult($expectedResult = [
            'hits' => [],
            'page' => 1,
            'hitsPerPage' => $builder->limit,
            'totalPages' => 1,
            'totalHits' => 0,
            'processingTimeMs' => 1,
            'query' => 'mustang',
        ]));

        $result = $this->engine->search($builder);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * @return void
     */
    public function testSubmittingACallableSearchWithRawSearchMethodWorks(): void
    {
        $builder = new Builder(
            $this->SearchableUsers,
            $query = 'mustang',
            function ($meilisearch, $query, array $options) {
                $options['filter'] = 'foo=1';

                return $meilisearch->rawSearch($query, $options);
            },
        );

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($query, ['filter' => 'foo=1'])->andReturn($expectedResult = [
            'hits' => [],
            'page' => 1,
            'hitsPerPage' => $builder->limit,
            'totalPages' => 1,
            'totalHits' => 0,
            'processingTimeMs' => 1,
            'query' => $query,
        ]);

        $result = $this->engine->search($builder);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * @return void
     */
    public function testWhereInConditionsAreApplied(): void
    {
        $builder = new Builder($this->SearchableUsers, '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->where('baz', null);
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND baz IS NULL AND qux IN [1, 2] AND quux IN [1, 2]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testNullInequalityConditionsAreApplied(): void
    {
        $builder = new Builder($this->SearchableUsers, '');
        $builder->where('baz', '!=', null);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'baz IS NOT NULL',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testAModelIsIndexedWithACustomMeilisearchKey(): void
    {
        $model = $this->createChirpQuietly(['explorator_id' => 'my-meilisearch-key.5']);

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->once()->with([[
            'explorator_id' => 'my-meilisearch-key.5',
            'content' => $model->content,
        ]], 'explorator_id');

        $this->engine->update([$model]);
    }

    /**
     * @return void
     */
    public function testFlushAModelWithACustomMeilisearchKey(): void
    {
        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteAllDocuments');

        $this->engine->flush($this->Chirps);
    }

    /**
     * @return void
     */
    public function testUpdateEmptySearchableArrayDoesNotAddDocumentsToIndex(): void
    {
        $_ENV['user.toSearchableArray'] = [];

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldNotReceive('addDocuments');

        $entity = $this->SearchableUsers->newEmptyEntity();
        $this->engine->update([$entity]);
    }

    /**
     * @return void
     */
    public function testPaginationCorrectParameters(): void
    {
        $perPage = 5;
        $page = 2;

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with('mustang', [
            'filter' => 'foo=1',
            'hitsPerPage' => $perPage,
            'page' => $page,
        ]);

        $builder = new Builder($this->SearchableUsers, 'mustang', function ($meilisearch, $query, array $options) {
            $options['filter'] = 'foo=1';

            return $meilisearch->search($query, $options);
        });

        $this->engine->paginate($builder, $perPage, $page);
    }

    /**
     * @return void
     */
    public function testPaginationSortedParameter(): void
    {
        $perPage = 5;
        $page = 2;

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with('mustang', [
            'filter' => 'foo=1',
            'hitsPerPage' => $perPage,
            'page' => $page,
            'sort' => ['name:asc'],
        ]);

        $builder = new Builder($this->SearchableUsers, 'mustang', function ($meilisearch, $query, array $options) {
            $options['filter'] = 'foo=1';

            return $meilisearch->search($query, $options);
        });
        $builder->orderBy('name', 'asc');

        $this->engine->paginate($builder, $perPage, $page);
    }

    /**
     * @return void
     */
    public function testUpdateEmptySearchableArrayFromSoftDeletedModelDoesNotAddDocumentsToIndex(): void
    {
        $_ENV['chirp.toSearchableArray'] = [];

        $engine = new MeilisearchEngine($this->client, true);

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldNotReceive('addDocuments');

        $entity = new Chirp();
        $entity->setSource('Chirps');

        $engine->update([$entity]);
    }

    /**
     * @return void
     */
    public function testPerformingSearchWithoutCallbackWorks(): void
    {
        $builder = new Builder($this->SearchableUsers, '');

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->andReturn([]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testWhereConditionsAreApplied(): void
    {
        $builder = new Builder($this->SearchableUsers, '');
        $builder->where('foo', 'bar');
        $builder->where('key', 'value');

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND key="value"',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testWhereNotInConditionsAreApplied(): void
    {
        $builder = new Builder($this->SearchableUsers, '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);
        $builder->whereNotIn('eaea', [3]);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND qux IN [1, 2] AND quux IN [1, 2] AND eaea NOT IN [3]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testWhereInConditionsAreAppliedWithoutOtherConditions(): void
    {
        $builder = new Builder($this->SearchableUsers, '');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'qux IN [1, 2] AND quux IN [1, 2]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testWhereNotInConditionsAreAppliedWithoutOtherConditions(): void
    {
        $builder = new Builder($this->SearchableUsers, '');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);
        $builder->whereNotIn('eaea', [3]);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'qux IN [1, 2] AND quux IN [1, 2] AND eaea NOT IN [3]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testEmptyWhereInConditionsAreAppliedCorrectly(): void
    {
        $builder = new Builder($this->SearchableUsers, '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->whereIn('qux', []);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND qux IN []',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testDeleteAllIndexesWorksWithPagination(): void
    {
        $this->client->shouldReceive('getIndexes')->andReturn($indexesResults = m::mock(IndexesResults::class));
        $indexesResults->shouldReceive('getResults')->once();

        $this->engine->deleteAllIndexes();
    }

    /**
     * Build a MeilisearchEngine wired with embedding settings for the
     * SearchableUsers table (using the FakeEmbeddings provider).
     *
     * @param array<string, mixed> $embeddingOverrides
     * @return \Crustum\Explorator\Engine\MeilisearchEngine
     */
    protected function embeddingsEngine(array $embeddingOverrides = []): MeilisearchEngine
    {
        $config = [
            'model-settings' => [
                MeilisearchSearchableUsersTable::class => [
                    'embedding' => array_merge([
                        'embedder' => 'default',
                        'dimensions' => 2,
                        'provider' => 'openai',
                        'model' => 'text-embedding-test',
                    ], $embeddingOverrides),
                ],
            ],
        ];

        return new MeilisearchEngine($this->client, (bool)Configure::read('Explorator.soft_delete'), $config);
    }

    /**
     * @return void
     */
    public function testUpdateAddsGeneratedEmbeddingsToVectors(): void
    {
        $this->fakeEmbeddings([[[0.3, 0.4]]]);

        $model = $this->createUserQuietly(['name' => 'Generate this']);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->once()->with(m::on(fn($objects): bool => ($objects[0]['_vectors']['default'] ?? null) === [0.3, 0.4]), 'id');

        $this->embeddingsEngine()->update([$model]);
    }

    /**
     * @return void
     */
    public function testUpdateAddsPrecomputedEmbeddingsToVectors(): void
    {
        $model = $this->createUserQuietly(['name' => 'Precomputed']);
        $model->set('embedding', [0.1, 0.2]);

        $engine = $this->embeddingsEngine();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->once()->with(m::on(fn($objects): bool => ($objects[0]['_vectors']['default'] ?? null) === [0.1, 0.2]), 'id');

        $engine->update([$model]);
    }

    /**
     * @return void
     */
    public function testUpdateDoesNotAddVectorsWhenUsingNativeEmbeddings(): void
    {
        $this->fakeEmbeddings([]);

        $model = $this->createUserQuietly(['name' => 'Model 1']);

        $engine = $this->embeddingsEngine(['driver' => 'meilisearch']);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->once()->with([$model->toSearchableArray()], 'id');

        $engine->update([$model]);

        Embeddings::assertNothingGenerated();
    }

    /**
     * @return void
     */
    public function testUpdatePreservesUserProvidedVectorsWhenUsingNativeEmbeddings(): void
    {
        $this->fakeEmbeddings([]);

        $model = $this->createUserQuietly(['name' => 'Model 1']);

        $_ENV['user.toSearchableArray'] = fn($entity): array => [
            'id' => $entity->id,
            'name' => 'Model 1',
            '_vectors' => [
                'default' => [
                    'embeddings' => [0.1, 0.2],
                    'regenerate' => false,
                ],
            ],
        ];

        $engine = $this->embeddingsEngine(['driver' => 'meilisearch']);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->once()->with([[
            'id' => $model->id,
            'name' => 'Model 1',
            '_vectors' => [
                'default' => [
                    'embeddings' => [0.1, 0.2],
                    'regenerate' => false,
                ],
            ],
        ]], 'id');

        $engine->update([$model]);

        Embeddings::assertNothingGenerated();
    }

    /**
     * @return void
     */
    public function testSemanticSearchGeneratesAQueryVector(): void
    {
        $this->fakeEmbeddings([[[0.25, 0.75]]]);

        $engine = $this->embeddingsEngine();

        $expected = [
            'filter' => 'status="published"',
            'hitsPerPage' => 10,
            'vector' => [0.25, 0.75],
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => 1.0,
            ],
            'rankingScoreThreshold' => 0.0,
        ];
        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with('conceptual query', m::on(fn($parameters): bool => $parameters === $expected))->andReturn(['hits' => []]);

        $builder = (new Builder($this->SearchableUsers, 'conceptual query'))
            ->semantic(minSimilarity: 0)
            ->where('status', 'published')
            ->take(10);

        $engine->search($builder);
    }

    /**
     * @return void
     */
    public function testSemanticSearchWithNativeEmbeddingsOmitsTheQueryVector(): void
    {
        $this->fakeEmbeddings([]);

        $engine = $this->embeddingsEngine(['driver' => 'meilisearch']);

        $expected = [
            'hitsPerPage' => 10,
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => 1.0,
            ],
        ];
        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with('conceptual query', m::on(fn($parameters): bool => $parameters === $expected))->andReturn(['hits' => []]);

        $builder = (new Builder($this->SearchableUsers, 'conceptual query'))
            ->semantic()
            ->take(10);

        $engine->search($builder);

        Embeddings::assertNothingGenerated();
    }

    /**
     * @return void
     */
    public function testHybridSearchWithNativeEmbeddingsAcceptsAPrecomputedQueryVector(): void
    {
        $this->fakeEmbeddings([]);

        $engine = $this->embeddingsEngine(['driver' => 'meilisearch']);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with('combined query', m::on(fn($parameters): bool => $parameters === [
            'vector' => [0.4, 0.6],
            'hitsPerPage' => 5,
            'page' => 2,
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => 2 / 3,
            ],
        ]))->andReturn(['hits' => []]);

        $builder = (new Builder($this->SearchableUsers, 'combined query'))
            ->options(['vector' => [0.4, 0.6]])
            ->hybrid(textWeight: 1, semanticWeight: 2);

        $engine->paginate($builder, 5, 2);

        Embeddings::assertNothingGenerated();
    }

    /**
     * @return void
     */
    public function testSemanticSearchRejectsAnUnsupportedEmbeddingDriver(): void
    {
        $engine = $this->embeddingsEngine(['driver' => 'foo']);

        $builder = (new Builder($this->SearchableUsers, 'conceptual query'))->semantic();

        $this->expectException(ExploratorException::class);
        $this->expectExceptionMessage('The [foo] Meilisearch embedding driver is not supported.');

        $engine->search($builder);
    }

    /**
     * @return void
     */
    public function testHybridSearchAcceptsPrecomputedQueryVectorAndNormalizesWeights(): void
    {
        $engine = $this->embeddingsEngine();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with('combined query', m::on(fn($parameters): bool => $parameters === [
            'vector' => [0.4, 0.6],
            'hitsPerPage' => 5,
            'page' => 2,
            'hybrid' => [
                'embedder' => 'default',
                'semanticRatio' => 2 / 3,
            ],
            'rankingScoreThreshold' => 0.5,
        ]))->andReturn(['hits' => []]);

        $builder = (new Builder($this->SearchableUsers, 'combined query'))
            ->options(['vector' => [0.4, 0.6]])
            ->hybrid(textWeight: 1, semanticWeight: 2, minSimilarity: 0.5);

        $engine->paginate($builder, 5, 2);
    }
}
