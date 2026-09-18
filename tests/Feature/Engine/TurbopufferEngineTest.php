<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Engine;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Crustum\Ai\Ai;
use Crustum\Ai\AiManager;
use Crustum\Ai\Embeddings;
use Crustum\Ai\Providers\OpenAiProvider;
use Crustum\Ai\Registry\ProviderRegistry;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engine\TurbopufferEngine;
use Crustum\Explorator\Exception\ExploratorException;
use Crustum\Explorator\Model\Behavior\SearchableBehavior;
use Crustum\Explorator\Service\Turbopuffer\TurbopufferClient;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Mockery as m;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for TurbopufferEngine.
 */
class TurbopufferEngineTest extends FeatureTestCase
{
    /**
     * @var \Crustum\Explorator\Engine\TurbopufferEngine
     */
    protected TurbopufferEngine $engine;

    /**
     * @var \TestApp\Model\Table\SearchableUsersTable
     */
    protected SearchableUsersTable $SearchableUsers;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(TurbopufferClient::class)) {
            $this->markTestSkipped('Turbopuffer engine not available');
        }

        $this->SearchableUsers = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $this->SearchableUsers);

        SearchableBehavior::enableSyncingFor('SearchableUsers');

        $this->engine = $this->makeEngine($this->httpReturning([]));
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
     * Build an engine backed by a mocked HTTP client.
     *
     * @param \Cake\Http\Client $http Mocked HTTP client
     * @param array<string, mixed> $config Engine configuration
     */
    protected function makeEngine(Client $http, array $config = []): TurbopufferEngine
    {
        $client = new TurbopufferClient($http, $config);

        return new TurbopufferEngine($client, $config, false);
    }

    /**
     * Build a mocked HTTP client returning the given JSON body.
     *
     * @param array<string, mixed> $json Decoded response body
     * @param int $status HTTP status code
     * @return \Cake\Http\Client
     */
    protected function httpReturning(array $json, int $status = 200): Client
    {
        $response = m::mock(Response::class);
        $response->shouldReceive('getStatusCode')->andReturn($status);
        $response->shouldReceive('getJson')->andReturn($json);

        $http = m::mock(Client::class);
        $http->shouldReceive('send')->andReturn($response);

        return $http;
    }

    /**
     * @param array<string, mixed> $data Entity data
     * @return \Cake\Datasource\EntityInterface
     */
    protected function createUserQuietly(array $data = []): object
    {
        return $this->SearchableUsers->withoutSyncingToSearch(function () use ($data): object {
            $entity = $this->SearchableUsers->newEntity(array_merge([
                'name' => 'Sample',
                'email' => 'sample@example.test',
            ], $data));

            return $this->SearchableUsers->saveOrFail($entity);
        });
    }

    /**
     * @return void
     */
    public function testUpdateSendsUpsertRows(): void
    {
        $http = $this->httpReturning([]);
        $this->engine = $this->makeEngine($http);

        $model = $this->createUserQuietly();

        $this->engine->update([$model]);

        $http->shouldHaveReceived('send');
    }

    /**
     * @return void
     */
    public function testDeleteSendsDeletes(): void
    {
        $http = $this->httpReturning([]);
        $this->engine = $this->makeEngine($http);

        $model = $this->createUserQuietly();

        $this->engine->delete([$model]);

        $http->shouldHaveReceived('send');
    }

    /**
     * @return void
     */
    public function testFlushDeletesIndex(): void
    {
        $http = $this->httpReturning([]);
        $this->engine = $this->makeEngine($http);

        $this->engine->flush($this->SearchableUsers);

        $http->shouldHaveReceived('send');
    }

    /**
     * @return void
     */
    public function testCreateIndexIsNotSupported(): void
    {
        $this->expectException(ExploratorException::class);

        $this->engine->createIndex('users');
    }

    /**
     * @return void
     */
    public function testInvalidNamespaceThrows(): void
    {
        $this->expectException(ExploratorException::class);

        (new TurbopufferClient($this->httpReturning([]), []))->namespace('invalid namespace!');
    }

    /**
     * @return void
     */
    public function testMapIdsExtractsIds(): void
    {
        $ids = $this->engine->mapIds(['rows' => [
            ['id' => '1'],
            ['id' => '2'],
        ]]);

        $this->assertSame(['1', '2'], $ids->toList());
    }

    /**
     * @return void
     */
    public function testMapReturnsEmptyForNoRows(): void
    {
        $results = $this->engine->map(new Builder($this->SearchableUsers, 'foo'), []);

        $this->assertCount(0, $results);
    }

    /**
     * @return void
     */
    public function testGetTotalCountReadsTotal(): void
    {
        $this->assertSame(7, $this->engine->getTotalCount(['total' => 7, 'rows' => [['id' => '1']]]));
    }

    /**
     * @return void
     */
    public function testSearchReturnsMappedRows(): void
    {
        $http = $this->httpReturning([
            'rows' => [['id' => '1', 'name' => 'zonda']],
            'total' => 1,
        ]);
        $this->engine = $this->makeEngine($http, [
            'model-settings' => [
                SearchableUsersTable::class => [
                    'searchable-attributes' => ['name' => 1, 'email' => 1],
                ],
            ],
        ]);

        $this->createUserQuietly(['name' => 'zonda']);

        $results = $this->engine->search(new Builder($this->SearchableUsers, 'zonda'));

        $this->assertArrayHasKey('rows', $results);
        $this->assertSame('1', (string)$results['rows'][0]['id']);
    }

    /**
     * @return void
     */
    public function testBuildSearchParametersForFullTextSearch(): void
    {
        $this->engine = $this->makeEngine($this->httpReturning([]), [
            'model-settings' => [
                SearchableUsersTable::class => [
                    'searchable-attributes' => ['name' => 1, 'email' => 1],
                ],
            ],
        ]);

        $parameters = $this->engine->buildSearchParameters(new Builder($this->SearchableUsers, 'zonda'), 10);

        $this->assertSame(10, $parameters['limit']);
        $this->assertArrayHasKey('rank_by', $parameters);
    }

    /**
     * @return void
     */
    public function testBuildSearchParametersForHybridSearch(): void
    {
        $this->engine = $this->makeEngine($this->httpReturning([]), [
            'model-settings' => [
                SearchableUsersTable::class => [
                    'searchable-attributes' => ['name' => 1, 'email' => 1],
                    'embedding' => [
                        'attribute' => 'embedding',
                        'driver' => 'turbopuffer',
                    ],
                    'schema' => [
                        'embedding' => [
                            'type' => 'string',
                            'embed' => ['model' => 'some-model', 'dimensions' => 2],
                        ],
                    ],
                ],
            ],
        ]);

        $builder = (new Builder($this->SearchableUsers, 'zonda'))->hybrid(1, 1);

        $parameters = $this->engine->buildSearchParameters($builder, 10);

        $this->assertArrayHasKey('queries', $parameters);
        $this->assertArrayHasKey('rerank_by', $parameters);
        $this->assertSame([1, 1], $parameters['rerank_by'][1]['weights']);
    }

    /**
     * @return void
     */
    public function testSemanticSearchUsesEmbeddings(): void
    {
        if (!class_exists(Embeddings::class)) {
            $this->markTestSkipped('crustum/cakephp-ai is required to test semantic search.');
        }

        $this->fakeEmbeddings([[[0.1, 0.2]]]);

        $http = $this->httpReturning([
            'rows' => [['id' => '1', 'name' => 'zonda']],
            'total' => 1,
        ]);
        $this->engine = $this->makeEngine($http, [
            'model-settings' => [
                SearchableUsersTable::class => [
                    'searchable-attributes' => ['name' => 1, 'email' => 1],
                    'embedding' => [
                        'attribute' => 'embedding',
                        'driver' => 'crustum-ai',
                        'dimensions' => 2,
                        'provider' => 'openai',
                        'model' => 'text-embedding-3-small',
                    ],
                ],
            ],
        ]);

        $this->createUserQuietly(['name' => 'zonda']);

        $results = $this->engine->search((new Builder($this->SearchableUsers, 'zonda'))->semantic());

        $this->assertSame('1', (string)$results['rows'][0]['id']);
    }

    /**
     * Fake embeddings generation via the Crustum AI package.
     *
     * @param array<int, mixed> $responses Embedding responses
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
}
