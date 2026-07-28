<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Engines;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\Algolia4Engine;
use Crustum\Explorator\Job\RemovableExploratorCollection;
use Crustum\Explorator\Model\Behavior\SearchableBehavior;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Mockery as m;
use TestApp\Model\Entity\Chirp;
use TestApp\Model\Entity\MeilisearchSearchableUser;
use TestApp\Model\Table\ChirpsTable;
use TestApp\Model\Table\MeilisearchSearchableUsersTable;

/**
 * Feature tests for Algolia4Engine.
 */
class Algolia4EngineTest extends FeatureTestCase
{
    /**
     * @var \Mockery\MockInterface&\Algolia\AlgoliaSearch\Api\SearchClient
     */
    protected SearchClient $client;

    /**
     * @var \Crustum\Explorator\Engines\Algolia4Engine
     */
    protected Algolia4Engine $engine;

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

        if (!class_exists(SearchClient::class)) {
            $this->markTestSkipped('Algolia client not installed');
        }

        Configure::write('Explorator.driver', 'algolia4-testing');
        Configure::write('Explorator.soft_delete', false);

        $this->client = m::spy(SearchClient::class);
        $this->engine = new Algolia4Engine($this->client, (bool)Configure::read('Explorator.soft_delete'));

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

        $this->client->shouldReceive('saveObjects')->once()->with('users', [[
            'id' => $model->id,
            'name' => $model->name,
            'email' => $model->email,
            'objectID' => $model->getExploratorKey(),
        ]], false);

        $this->engine->update([$model]);
    }

    /**
     * @return void
     */
    public function testDeleteRemovesObjectsToIndex(): void
    {
        $model = $this->createUserQuietly();

        $this->client->shouldReceive('deleteObjects')->once()->with('users', [1], false);

        $this->engine->delete([$model]);
    }

    /**
     * @return void
     */
    public function testDeleteRemovesObjectsToIndexWithACustomSearchKey(): void
    {
        $model = $this->createChirpQuietly(['explorator_id' => 'my-algolia-key.5']);

        $this->client->shouldReceive('deleteObjects')->once()->with('chirps', ['my-algolia-key.5'], false);

        $this->engine->delete([$model]);
    }

    /**
     * @return void
     */
    public function testDeleteWithRemovableExploratorCollectionUsingCustomSearchKey(): void
    {
        $model = $this->createChirpQuietly(['explorator_id' => 'my-algolia-key.5']);
        $collection = unserialize(serialize(RemovableExploratorCollection::fromEntities([$model])));

        $this->client->shouldReceive('deleteObjects')->once()->with('chirps', ['my-algolia-key.5'], false);

        $this->engine->delete($collection);
    }

    /**
     * @return void
     */
    public function testSearchSendsCorrectParametersToAlgolia(): void
    {
        $this->client->shouldReceive('searchSingleIndex')->once()->with(
            'users',
            ['query' => 'zonda', 'filters' => "foo:'1'"],
        );

        $builder = new Builder($this->SearchableUsers, 'zonda');
        $builder->where('foo', 1);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testSearchSendsCorrectParametersToAlgoliaForWhereInSearch(): void
    {
        $this->client->shouldReceive('searchSingleIndex')->once()->with(
            'users',
            ['query' => 'zonda', 'filters' => "foo:'1' AND (bar:'1' OR bar:'2')"],
        );

        $builder = new Builder($this->SearchableUsers, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', [1, 2]);
        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testSearchSendsCorrectParametersToAlgoliaForEmptyWhereInSearch(): void
    {
        $this->client->shouldReceive('searchSingleIndex')->once()->with(
            'users',
            ['query' => 'zonda', 'filters' => "foo:'1' AND 0:1"],
        );

        $builder = new Builder($this->SearchableUsers, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', []);
        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testMapCorrectlyMapsResultsToModels(): void
    {
        $this->createUserQuietly(['name' => 'zonda']);

        $results = $this->engine->map(new Builder($this->SearchableUsers, 'zonda'), [
            'nbHits' => 1,
            'hits' => [
                ['objectID' => 1, 'id' => 1, '_rankingInfo' => ['nbTypos' => 0]],
            ],
        ]);

        $this->assertCount(1, $results);
        $first = collection($results)->first();
        $this->assertSame(['_rankingInfo' => ['nbTypos' => 0]], $first->exploratorMetadata());
        $this->assertSame(1, $first->id);
        $this->assertSame('zonda', $first->name);
    }

    /**
     * @return void
     */
    public function testAModelIsIndexedWithACustomAlgoliaKey(): void
    {
        $model = $this->createChirpQuietly(['explorator_id' => 'my-algolia-key.1']);

        $this->client->shouldReceive('saveObjects')->once()->with('chirps', [[
            'content' => $model->content,
            'objectID' => 'my-algolia-key.1',
        ]], false);

        $this->engine->update([$model]);
    }

    /**
     * @return void
     */
    public function testAModelIsRemovedWithACustomAlgoliaKey(): void
    {
        $model = $this->createChirpQuietly(['explorator_id' => 'my-algolia-key.1']);

        $this->client->shouldReceive('deleteObjects')->once()->with('chirps', ['my-algolia-key.1'], false);

        $this->engine->delete([$model]);
    }

    /**
     * @return void
     */
    public function testFlushAModelWithACustomAlgoliaKey(): void
    {
        $this->client->shouldReceive('clearObjects')->once()->with('chirps');

        $this->engine->flush($this->Chirps);
    }

    /**
     * @return void
     */
    public function testUpdateEmptySearchableArrayDoesNotAddObjectsToIndex(): void
    {
        $_ENV['user.toSearchableArray'] = [];

        $this->client->shouldNotReceive('saveObjects')->with('users');

        $this->engine->update([$this->SearchableUsers->newEntity([])]);

        unset($_ENV['user.toSearchableArray']);
    }

    /**
     * @return void
     */
    public function testUpdateEmptySearchableArrayFromSoftDeletedModelDoesNotAddObjectsToIndex(): void
    {
        Configure::write('Explorator.soft_delete', true);
        $this->engine = new Algolia4Engine($this->client, true);
        $_ENV['chirp.toSearchableArray'] = [];

        $this->client->shouldNotReceive('saveObjects')->with('chirps');

        $this->engine->update([$this->Chirps->newEntity([])]);

        unset($_ENV['chirp.toSearchableArray']);
    }

    /**
     * @return void
     */
    public function testSearchSendsCorrectParametersToAlgoliaForWhereNotInSearch(): void
    {
        $this->client->shouldReceive('searchSingleIndex')->once()->with(
            'users',
            ['query' => 'zonda', 'filters' => "NOT foo:'1' AND NOT foo:'2'"],
        );

        $builder = new Builder($this->SearchableUsers, 'zonda');
        $builder->whereNotIn('foo', [1, 2]);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testSearchIgnoresEmptyWhereNotInSearch(): void
    {
        $this->client->shouldReceive('searchSingleIndex')->once()->with(
            'users',
            ['query' => 'zonda'],
        );

        $builder = new Builder($this->SearchableUsers, 'zonda');
        $builder->whereNotIn('foo', []);

        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testSearchSendsCorrectParametersToAlgoliaForMixedSearch(): void
    {
        $this->client->shouldReceive('searchSingleIndex')->once()->with(
            'users',
            ['query' => 'zonda', 'filters' => "foo:'1' AND (bar:'1' OR bar:'2') AND NOT baz:'1' AND NOT baz:'2'"],
        );

        $builder = new Builder($this->SearchableUsers, 'zonda');
        $builder->where('foo', 1)
            ->whereIn('bar', [1, 2])
            ->whereNotIn('baz', [1, 2]);
        $this->engine->search($builder);
    }

    /**
     * @return void
     */
    public function testSearchSendsBooleanAndStringInequalityFiltersToAlgolia(): void
    {
        $this->client->shouldReceive('searchSingleIndex')->once()->with(
            'users',
            [
                'query' => 'zonda',
                'filters' => "is_live:true AND is_archived:false AND NOT status:'draft' AND NOT label:'manager\\'s draft\\\\review' AND NOT is_deleted:true AND (is_featured:true OR is_featured:false) AND NOT is_hidden:true AND NOT is_hidden:false",
            ],
        );

        $builder = new Builder($this->SearchableUsers, 'zonda');
        $builder->where('is_live', true)
            ->where('is_archived', '=', false)
            ->where('status', '!=', 'draft')
            ->where('label', '!=', "manager's draft\\review")
            ->where('is_deleted', '!=', true)
            ->whereIn('is_featured', [true, false])
            ->whereNotIn('is_hidden', [true, false]);

        $this->engine->search($builder);
    }
}
