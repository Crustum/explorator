<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Integration;

use Cake\Core\Configure;
use Cake\ORM\Locator\TableLocator;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engine\TypesenseEngine;
use Crustum\Explorator\EngineManager;
use PHPUnit\Framework\Attributes\Group;
use TestApp\Model\Entity\SearchableUser;
use TestApp\Model\Table\SearchableUsersTable;
use Throwable;
use Typesense\Client;

/**
 * Live Typesense integration (exact maps + empty-query paginate + max-int overflow).
 *
 * Skipped without a real TYPESENSE_API_KEY (not the placeholder `xyz`).
 */
#[Group('typesense')]
#[Group('external-network')]
class TypesenseSearchableTest extends IntegrationTestCase
{
    use SearchableTestsTrait;

    /**
     * @inheritDoc
     */
    protected function exploratorDriver(): string
    {
        return 'typesense';
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $apiKey = (string)Configure::read('Explorator.typesense.client-settings.api_key', '');
        if ($apiKey === '' || $apiKey === 'xyz') {
            $this->markTestSkipped('Configure Explorator.typesense client-settings (TYPESENSE_API_KEY) to run live Typesense tests.');
        }

        Configure::write('Explorator.typesense.model-settings.' . SearchableUsersTable::class, [
            'collection-schema' => [
                'fields' => [
                    [
                        'name' => 'id',
                        'type' => 'string',
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                    ],
                    [
                        'name' => 'age',
                        'type' => 'int64',
                    ],
                ],
            ],
            'search-parameters' => [
                'query_by' => 'name',
            ],
        ]);

        $this->setUpSearchableSuite();

        $engine = (new EngineManager())->engine('typesense');
        $indexName = $this->SearchableUsers->searchableAs();
        try {
            $engine->deleteIndex($indexName);
        } catch (Throwable) {
        }

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
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
                'Linkwood Larkin',
                'Prof. Larry Prosacco DVM',
                'Reta Larkin',
                'Larry Casper',
                'Lara North',
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
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
                'Reta Larkin',
                'Lara North',
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
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
                'Linkwood Larkin',
                'Prof. Larry Prosacco DVM',
                'Reta Larkin',
                'Larry Casper',
                'Lara North',
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
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
                'Linkwood Larkin',
                'Prof. Larry Prosacco DVM',
                'Reta Larkin',
                'Larry Casper',
                'Lara North',
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
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
            ]),
            $this->pluckNameById($page1),
        );

        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Linkwood Larkin',
                'Prof. Larry Prosacco DVM',
                'Reta Larkin',
                'Larry Casper',
                'Lara North',
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
                'Amos Larson Sr.',
                'Dana Larson Sr.',
                'Dax Larkin',
                'Gudrun Larkin',
                'Otis Larson MD',
            ]),
            $this->pluckNameById($page1),
        );

        $this->assertSame(
            $this->expectedNameMapFromDb([
                'Reta Larkin',
                'Lara North',
            ]),
            $this->pluckNameById($page2),
        );
    }

    /**
     * @return void
     */
    public function testItCanUsePaginatedSearchWithEmptyQueryCallback(): void
    {
        $res = $this->itCanUsePaginatedSearchWithEmptyQueryCallback();

        $this->assertSame(44, $res->totalCount());
        $this->assertSame(3, $res->pageCount());
    }

    /**
     * @return void
     */
    public function testItCanUsePaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawPaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateRawUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseSimplePaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawSimplePaginatedSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateRawUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawGetSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfGetUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    /**
     * @return void
     */
    public function testItCanUseRawCursorSearchWithAfterRawSearchCallback(): void
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfCursorUsingAfterRawSearchCallback();
        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    /**
     * @return void
     */
    public function testItHandlesPaginationWithMaxIntOverflow(): void
    {
        $maxInt = 4294967295;
        $perPage = 10;
        $overflowPage = 4294967296;
        $expectedPage = (int)floor($maxInt / $perPage);

        $rawSearchResult = null;

        $results = $this->SearchableUsers->search('lar')
            ->withRawResults(function ($result) use (&$rawSearchResult): void {
                $rawSearchResult = $result;
            })
            ->paginate($perPage, 'page', $overflowPage);

        $this->assertSame($overflowPage, $results->currentPage());
        $this->assertSame($perPage, $results->perPage());
        $this->assertSame($expectedPage, $rawSearchResult['page']);
        $this->assertSame($perPage, $rawSearchResult['request_params']['per_page']);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithWhereComparisons(): void
    {
        $this->itCanMakeWhereComparisons();
    }

    /**
     * @return void
     */
    public function testItCanUseUserProvidedEmbeddingsForSemanticSearch(): void
    {
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('typesense/typesense-php is required for this test.');
        }

        $index = 'semantic_' . bin2hex(random_bytes(6));

        $table = new class (['alias' => 'SemanticTypesense', 'table' => 'semantic_typesense']) extends SearchableUsersTable {
            /**
             * @var string
             */
            public static string $index = '';

            /**
             * @inheritDoc
             */
            public function searchableAs(): string
            {
                return static::$index;
            }

            /**
             * @inheritDoc
             */
            public function indexableAs(): string
            {
                return static::$index;
            }
        };
        $table::$index = $index;
        $tableClass = $table::class;

        $dimensions = 384;

        Configure::write('Explorator.typesense.model-settings.' . $tableClass, [
            'collection-schema' => [
                'fields' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => $dimensions],
                ],
            ],
            'search-parameters' => [
                'query_by' => 'name',
            ],
        ]);

        $engine = new TypesenseEngine(
            new Client((array)Configure::read('Explorator.typesense.client-settings', [])),
            1000,
            false,
            [
                'model-settings' => [
                    $tableClass => [
                        'embedding' => [
                            'attribute' => 'embedding',
                            'dimensions' => $dimensions,
                        ],
                    ],
                ],
            ],
        );

        $locator = new TableLocator();
        $locator->set('SemanticTypesense', $table);

        $engine->setTableLocator($locator);

        $newEntity = function (int $id, string $name, array $vector): SearchableUser {
            $entity = new class (['id' => $id, 'name' => $name]) extends SearchableUser {
                /**
                 * @return array<string, mixed>
                 */
                public function toSearchableArray(): array
                {
                    return [
                        'id' => (string)$this->id,
                        'name' => $this->name,
                    ];
                }

                /**
                 * @return string|array<int, float>
                 */
                public function toSearchableEmbedding(): string|array
                {
                    return $this->get('embedding');
                }
            };
            $entity->set('embedding', $vector);
            $entity->setSource('SemanticTypesense');

            return $entity;
        };

        try {
            $catVector = array_fill(0, $dimensions, 0.001953125);
            $catVector[0] = 1.0;

            $rocketVector = array_fill(0, $dimensions, 0.001953125);
            $rocketVector[$dimensions - 1] = 1.0;

            $cat = $newEntity(1, 'A sleeping cat', $catVector);
            $rocket = $newEntity(2, 'A rocket launch', $rocketVector);

            $engine->update([$cat, $rocket]);

            $this->assertGreaterThan(4000, strlen(implode(', ', $catVector)));

            $results = $engine->search(
                (new Builder($table, 'a relaxed pet'))
                    ->options(['vector' => $catVector])
                    ->semantic(),
            );

            $this->assertSame('1', $results['hits'][0]['document']['id']);

            $results = $engine->search(
                (new Builder($table, 'rocket'))
                    ->options(['vector' => $rocketVector])
                    ->hybrid(),
            );

            $this->assertSame('2', $results['hits'][0]['document']['id']);
        } finally {
            $engine->deleteIndex($index);
        }
    }
}
