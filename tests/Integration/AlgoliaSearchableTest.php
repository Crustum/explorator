<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Integration;

use Cake\Core\Configure;
use Crustum\Explorator\EngineManager;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * Live Algolia integration.
 *
 * Skipped without Configure Explorator.algolia.id / secret.
 */
#[Group('algolia')]
#[Group('external-network')]
class AlgoliaSearchableTest extends IntegrationTestCase
{
    use SearchableTestsTrait;

    /**
     * @inheritDoc
     */
    protected function exploratorDriver(): string
    {
        return 'algolia';
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (!(string)Configure::read('Explorator.algolia.id') || !(string)Configure::read('Explorator.algolia.secret')) {
            $this->markTestSkipped('Configure Explorator.algolia.id / secret (ALGOLIA_APP_ID / ALGOLIA_SECRET) to run live Algolia tests.');
        }

        $this->setUpSearchableSuite();

        $engine = (new EngineManager())->engine('algolia');
        $indexName = $this->SearchableUsers->searchableAs();
        try {
            $engine->deleteIndex($indexName);
        } catch (Throwable) {
        }

        $this->SearchableUsers->importSearchable();
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
                'Larry Casper',
                'Lara North',
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
                'Linkwood Larkin',
                'Prof. Larry Prosacco DVM',
                'Reta Larkin',
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
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
                'Reta Larkin',
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
                'Larry Casper',
                'Lara North',
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
                'Linkwood Larkin',
                'Prof. Larry Prosacco DVM',
                'Reta Larkin',
            ], asString: true),
            array_map(strval(...), $this->itCanUseBasicSearchToFetchKeys()->toList()),
        );
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearchWithQueryCallbackToFetchKeys(): void
    {
        $this->assertSame(
            $this->expectedKeysFromDb([
                'Larry Casper',
                'Lara North',
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
                'Linkwood Larkin',
                'Prof. Larry Prosacco DVM',
                'Reta Larkin',
            ], asString: true),
            array_map(strval(...), $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys()->toList()),
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
                'Larry Casper',
                'Lara North',
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
            ]),
            $this->pluckNameById($page1),
        );

        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Gudrun Larkin',
                'Otis Larson MD',
                'Linkwood Larkin',
                'Prof. Larry Prosacco DVM',
                'Reta Larkin',
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
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
            ]),
            $this->pluckNameById($page1),
        );

        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Gudrun Larkin',
                'Otis Larson MD',
                'Reta Larkin',
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
        $this->assertArrayHasKey('processingTimeMS', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawPaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateRawUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMS', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseSimplePaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMS', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawSimplePaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateRawUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMS', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawGetSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfGetUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMS', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawCursorSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfCursorUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMS', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithWhereComparisons(): void
    {
        $this->itCanMakeWhereComparisons();
    }
}
