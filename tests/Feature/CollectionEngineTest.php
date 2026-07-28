<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use TestApp\Model\Entity\SearchableUserWithCustomSearchableData;
use TestApp\Model\Entity\SearchableUserWithUnloadedValue;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for CollectionEngine.
 */
class CollectionEngineTest extends FeatureTestCase
{
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

        Configure::write('Explorator.driver', 'collection');

        $this->SearchableUsers = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $this->SearchableUsers);

        $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity([
            'name' => 'Blake Torres',
            'email' => 'blake@example.test',
            'created' => new DateTime('+1 day'),
        ]));
        $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity([
            'name' => 'Alex Rivera',
            'email' => 'alex@example.test',
            'created' => new DateTime('+2 days'),
        ]));
    }

    /**
     * @return void
     */
    public function testItCanRetrieveResultsWithEmptySearch(): void
    {
        $models = $this->SearchableUsers->search()->get();

        $this->assertCount(2, $models);
    }

    /**
     * @return void
     */
    public function testItCanRetrieveResults(): void
    {
        $models = $this->SearchableUsers->search('Blake')->where('email', 'blake@example.test')->get();
        $this->assertCount(1, $models);
        $this->assertSame(1, $models->first()->id);

        $models = $this->SearchableUsers->search('Blake')->query(function ($query): void {
            $query->where(['email LIKE' => 'blake@example.test']);
        })->get();
        $this->assertCount(1, $models);
        $this->assertSame(1, $models->first()->id);

        $models = $this->SearchableUsers->search('Alex')->where('email', 'alex@example.test')->get();
        $this->assertCount(1, $models);
        $this->assertSame(2, $models->first()->id);

        $models = $this->SearchableUsers->search('Blake')->where('email', 'alex@example.test')->get();
        $this->assertCount(0, $models);

        $models = $this->SearchableUsers->search('example.test')->get();
        $this->assertCount(2, $models);

        $models = $this->SearchableUsers->search('example')->get();
        $this->assertCount(2, $models);

        $models = $this->SearchableUsers->search('foo')->get();
        $this->assertCount(0, $models);

        $models = $this->SearchableUsers->search('Alex')->where('email', 'blake@example.test')->get();
        $this->assertCount(0, $models);
    }

    /**
     * @return void
     */
    public function testItCanRetrieveResultsMatchingToCustomSearchableData(): void
    {
        $table = new class ([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]) extends SearchableUsersTable {
            /**
             * @inheritDoc
             */
            public function initialize(array $config): void
            {
                parent::initialize($config);
                $this->setEntityClass(SearchableUserWithCustomSearchableData::class);
            }
        };

        $models = $table->search('ekalB')->get();
        $this->assertCount(1, $models);
    }

    /**
     * @return void
     */
    public function testItCanPaginateResults(): void
    {
        $models = $this->SearchableUsers->search('Blake')->where('email', 'blake@example.test')->paginate();
        $this->assertCount(1, $models);

        $models = $this->SearchableUsers->search('Blake')->where('email', 'alex@example.test')->paginate();
        $this->assertCount(0, $models);

        $models = $this->SearchableUsers->search('example')->paginate();
        $this->assertCount(2, $models);

        $dummyQuery = function ($query): void {
            $query->where(['name !=' => 'Dummy']);
        };

        $models = $this->SearchableUsers->search('example')->query($dummyQuery)->orderBy('name')->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $this->firstName($models));

        $models = $this->SearchableUsers->search('example')->query($dummyQuery)->orderBy('name')->paginate(1, 'page', 2);
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $this->firstName($models));

        $models = $this->SearchableUsers->search('example')->query($dummyQuery)->orderByDesc('name')->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $this->firstName($models));

        $models = $this->SearchableUsers->search('example')->query($dummyQuery)->orderByDesc('name')->paginate(1, 'page', 2);
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $this->firstName($models));
    }

    /**
     * @return void
     */
    public function testLimitIsApplied(): void
    {
        $models = $this->SearchableUsers->search('example')->get();
        $this->assertCount(2, $models);

        $models = $this->SearchableUsers->search('example')->take(1)->get();
        $this->assertCount(1, $models);
    }

    /**
     * @return void
     */
    public function testItCanOrderResults(): void
    {
        $models = $this->SearchableUsers->search('example')->orderBy('name', 'asc')->paginate(1, 'page', 1);
        $this->assertSame('Alex Rivera', $this->firstName($models));

        $models = $this->SearchableUsers->search('example')->orderBy('name', 'desc')->paginate(1, 'page', 1);
        $this->assertSame('Blake Torres', $this->firstName($models));

        $models = $this->SearchableUsers->search('example')->orderByDesc('name')->paginate(1, 'page', 1);
        $this->assertSame('Blake Torres', $this->firstName($models));
    }

    /**
     * @return void
     */
    public function testItCanOrderByLatestAndOldest(): void
    {
        $models = $this->SearchableUsers->search('example')->latest()->paginate(1, 'page', 1);
        $this->assertSame('Alex Rivera', $this->firstName($models));

        $models = $this->SearchableUsers->search('example')->oldest()->paginate(1, 'page', 1);
        $this->assertSame('Blake Torres', $this->firstName($models));
    }

    /**
     * @return void
     */
    public function testItCanOrderByCustomModelCreatedAtTimestamp(): void
    {
        $table = new class ([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]) extends SearchableUsersTable {
            /**
             * @inheritDoc
             */
            public function getCreatedAtColumn(): string
            {
                return 'created';
            }
        };

        $query = $table->search()->latest();

        $this->assertCount(1, $query->orders);
        $this->assertSame('created', $query->orders[0]['column']);
    }

    /**
     * @return void
     */
    public function testItCallsMakeSearchableUsingBeforeSearching(): void
    {
        $table = new class ([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]) extends SearchableUsersTable {
            /**
             * @inheritDoc
             */
            public function initialize(array $config): void
            {
                parent::initialize($config);
                $this->setEntityClass(SearchableUserWithUnloadedValue::class);
            }
        };

        $models = $table->search('loaded')->get();

        $this->assertCount(2, $models);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithGreaterThan(): void
    {
        $models = $this->SearchableUsers->search()->where('name', '>', 'B')->get();

        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithLessThan(): void
    {
        $models = $this->SearchableUsers->search()->where('name', '<', 'B')->get();

        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $models->first()->name);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithGreaterThanOrEqual(): void
    {
        $models = $this->SearchableUsers->search()->where('name', '>=', 'B')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);

        $models = $this->SearchableUsers->search()->where('name', '>=', 'A')->get();
        $this->assertCount(2, $models);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithLessThanOrEqual(): void
    {
        $models = $this->SearchableUsers->search()->where('name', '<=', 'Alex Rivera')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $models->first()->name);

        $models = $this->SearchableUsers->search()->where('name', '<=', 'Blake Torres')->get();
        $this->assertCount(2, $models);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithNotEqual(): void
    {
        $models = $this->SearchableUsers->search()->where('name', '!=', 'Alex Rivera')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);

        $models = $this->SearchableUsers->search()->where('name', '!=', 'Blake Torres')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $models->first()->name);
    }

    /**
     * @return void
     */
    public function testItCanFilterWithMultipleWhereComparisons(): void
    {
        $models = $this->SearchableUsers->search()->where('name', '>', 'B')->where('name', '<', 'Z')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);

        $models = $this->SearchableUsers->search()->where('name', '>', 'A')->where('name', '<', 'B')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $models->first()->name);
    }

    /**
     * @param iterable<\Cake\Datasource\EntityInterface> $models Paginated or result set
     * @return string
     */
    protected function firstName(iterable $models): string
    {
        foreach ($models as $model) {
            return (string)$model->get('name');
        }

        $this->fail('Expected at least one model');
    }
}
