<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Integration;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Crustum\Explorator\Builder;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\Engines\MeilisearchEngine;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use TestApp\Model\Table\SearchableUsersTable;
use TestApp\Model\Table\VersionableUsersTable;
use Throwable;

/**
 * Live Meilisearch integration.
 *
 * Host/key come from loaded `Explorator.meilisearch` config (defaults to localhost:7700).
 */
#[Group('meilisearch')]
#[Group('external-network')]
class MeilisearchSearchableTest extends IntegrationTestCase
{
    use SearchableTestsTrait;

    /**
     * @inheritDoc
     */
    protected function exploratorDriver(): string
    {
        return 'meilisearch';
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Configure::write('Explorator.meilisearch.index-settings.' . SearchableUsersTable::class, [
            'filterableAttributes' => ['age'],
        ]);

        $this->setUpSearchableSuite();

        $engine = (new EngineManager())->engine('meilisearch');
        try {
            $engine->health();
        } catch (Throwable $throwable) {
            $this->markTestSkipped('Meilisearch not reachable via Explorator.meilisearch config: ' . $throwable->getMessage());
        }

        $indexName = $this->SearchableUsers->searchableAs();
        try {
            $engine->deleteIndex($indexName);
        } catch (Throwable) {
        }

        $engine->updateIndexSettings($indexName, [
            'filterableAttributes' => ['age'],
        ]);
        $this->SearchableUsers->importSearchable();
        $this->waitForSearchIndex();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($_ENV['user.toSearchableArray']);
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearch(): void
    {
        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Lara North',
                'Larry Casper',
                'Reta Larkin',
                'Linkwood Larkin',
                'Otis Larson MD',
                'Gudrun Larkin',
                'Dax Larkin',
                'Dana Larson Sr.',
                'Amos Larson Sr.',
                'Prof. Larry Prosacco DVM',
            ]),
            $this->pluckNameById($this->itCanUseBasicSearch()),
        );
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearchWithQueryCallback(): void
    {
        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Lara North',
                'Reta Larkin',
                'Otis Larson MD',
                'Gudrun Larkin',
                'Dax Larkin',
                'Dana Larson Sr.',
                'Amos Larson Sr.',
            ]),
            $this->pluckNameById($this->itCanUseBasicSearchWithQueryCallback()),
        );
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearchToFetchKeys(): void
    {
        $this->assertSame(
            $this->expectedKeysFromDb([
                'Lara North',
                'Larry Casper',
                'Reta Larkin',
                'Linkwood Larkin',
                'Otis Larson MD',
                'Gudrun Larkin',
                'Dax Larkin',
                'Dana Larson Sr.',
                'Amos Larson Sr.',
                'Prof. Larry Prosacco DVM',
            ]),
            $this->itCanUseBasicSearchToFetchKeys()->toList(),
        );
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearchWithQueryCallbackToFetchKeys(): void
    {
        $this->assertSame(
            $this->expectedKeysFromDb([
                'Lara North',
                'Larry Casper',
                'Reta Larkin',
                'Linkwood Larkin',
                'Otis Larson MD',
                'Gudrun Larkin',
                'Dax Larkin',
                'Dana Larson Sr.',
                'Amos Larson Sr.',
                'Prof. Larry Prosacco DVM',
            ]),
            $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys()->toList(),
        );
    }

    /**
     * @return void
     */
    public function testItReturnSameKeysWithQueryCallback(): void
    {
        $this->assertSame(
            $this->itCanUseBasicSearchToFetchKeys()->toList(),
            $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys()->toList(),
        );
    }

    /**
     * @return void
     */
    public function testItCanUsePaginatedSearch(): void
    {
        [$page1, $page2] = $this->itCanUsePaginatedSearch();

        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Lara North',
                'Larry Casper',
                'Reta Larkin',
                'Linkwood Larkin',
                'Otis Larson MD',
            ]),
            $this->pluckNameById($page1),
        );

        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Gudrun Larkin',
                'Dax Larkin',
                'Dana Larson Sr.',
                'Amos Larson Sr.',
                'Prof. Larry Prosacco DVM',
            ]),
            $this->pluckNameById($page2),
        );
    }

    /**
     * @return void
     */
    public function testItCanUsePaginatedSearchWithQueryCallback(): void
    {
        [$page1, $page2] = $this->itCanUsePaginatedSearchWithQueryCallback();

        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Lara North',
                'Reta Larkin',
                'Otis Larson MD',
            ]),
            $this->pluckNameById($page1),
        );

        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Gudrun Larkin',
                'Dax Larkin',
                'Dana Larson Sr.',
                'Amos Larson Sr.',
            ]),
            $this->pluckNameById($page2),
        );
    }

    /**
     * @return void
     */
    public function testItCanUsePaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawPaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateRawUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseSimplePaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawSimplePaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateRawUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawGetSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfGetUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawCursorSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfCursorUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    /**
     * @return void
     */
    public function testUsesDifferentIndexes(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('index')->with('table_v2')->andReturn($index = Mockery::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->with([1]);

        $table = new VersionableUsersTable([
            'alias' => 'VersionableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('VersionableUsers', $table);

        $entity = $table->newEmptyEntity();
        $entity->set('id', 1, ['guard' => false]);
        $entity->setSource('VersionableUsers');
        $entity->setNew(false);

        $engine = new MeilisearchEngine($client);
        $engine->delete([$entity]);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('index')->with('table')->once()->andReturn($index = Mockery::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->andReturn([]);

        $engine = new MeilisearchEngine($client);
        $builder = new Builder($table, '');
        $engine->search($builder);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithWhereComparisons(): void
    {
        $this->itCanMakeWhereComparisons();
    }
}
