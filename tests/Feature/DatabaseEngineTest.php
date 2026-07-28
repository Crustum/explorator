<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Crustum\Explorator\Model\Behavior\SearchableBehavior;
use TestApp\Model\Entity\AttributeSearchableUser;
use TestApp\Model\Table\BookmarksTable;
use TestApp\Model\Table\ChirpsTable;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for DatabaseEngine.
 */
class DatabaseEngineTest extends FeatureTestCase
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
        Configure::write('Explorator.driver', 'database');
        Configure::write('Explorator.soft_delete', false);

        $this->SearchableUsers = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $this->SearchableUsers);

        $this->resetUsers([
            [
                'name' => 'Blake Torres',
                'email' => 'blake@example.test',
            ],
            [
                'name' => 'Alex Rivera',
                'email' => 'alex@example.test',
            ],
        ]);
    }

    /**
     * @return void
     */
    public function testItCanRetrieveResultsWithEmptySearch(): void
    {
        $this->assertCount(2, $this->SearchableUsers->search()->get());
    }

    /**
     * @return void
     */
    public function testItDoesNotAddSearchWhereClausesWithEmptySearch(): void
    {
        $this->SearchableUsers->search('')->query(function ($query): void {
            $sql = strtolower((string)$query->sql());
            $this->assertDoesNotMatchRegularExpression('/\b(?:i)?like\b/', $sql);
        })->get();
    }

    /**
     * @return void
     */
    public function testItAddsSearchWhereClausesWithNonEmptySearch(): void
    {
        $this->SearchableUsers->search('Blake')->query(function ($query): void {
            $sql = strtolower((string)$query->sql());
            $this->assertMatchesRegularExpression('/\b(?:i)?like\b/', $sql);
            $this->assertStringContainsString('name', $sql);
            $this->assertStringContainsString('email', $sql);
        })->get();
    }

    /**
     * @return void
     */
    public function testItCanRetrieveResults(): void
    {
        $models = $this->SearchableUsers->search('Blake')->where('email', 'blake@example.test')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);

        $models = $this->SearchableUsers->search('Blake')->query(function ($query): void {
            $query->where(['email LIKE' => 'blake@example.test']);
        })->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);

        $models = $this->SearchableUsers->search('Alex')->where('email', 'alex@example.test')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $models->first()->name);

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
    public function testItCanPaginateResults(): void
    {
        $models = $this->SearchableUsers->search('Blake')->where('email', 'blake@example.test')->paginate(15, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertSame(1, $models->totalCount());

        $models = $this->SearchableUsers->search('Blake')->where('email', 'alex@example.test')->paginate(15, 'page', 1);
        $this->assertCount(0, $models);

        $models = $this->SearchableUsers->search('example')->paginate(15, 'page', 1);
        $this->assertCount(2, $models);
        $this->assertSame(2, $models->totalCount());
    }

    /**
     * @return void
     */
    public function testItCanSimplePaginateResults(): void
    {
        $models = $this->SearchableUsers->search('example')->simplePaginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertTrue($models->hasNextPage());
        $this->assertFalse($models->hasPrevPage());
    }

    /**
     * @return void
     */
    public function testLimitIsApplied(): void
    {
        $this->assertCount(2, $this->SearchableUsers->search('example')->get());
        $this->assertCount(1, $this->SearchableUsers->search('example')->take(1)->get());
    }

    /**
     * @return void
     */
    public function testTapIsApplied(): void
    {
        $this->assertCount(2, $this->SearchableUsers->search('example')->get());
        $this->assertCount(1, $this->SearchableUsers->search('example')->tap(function ($builder): void {
            $builder->take(1);
        })->get());
    }

    /**
     * @return void
     */
    public function testItCanOrderResults(): void
    {
        $models = $this->SearchableUsers->search('example')->orderBy('name', 'asc')->take(1)->get();
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $models->first()->name);

        $modelsPaginate = $this->SearchableUsers->search('example')->orderBy('name', 'asc')->paginate(1, 'page', 1);
        $this->assertCount(1, $modelsPaginate);
        $paginatedItems = iterator_to_array($modelsPaginate->items());
        $this->assertSame('Alex Rivera', $paginatedItems[0]->name);

        $modelsSimplePaginate = $this->SearchableUsers->search('example')->orderBy('name', 'asc')->simplePaginate(1, 'page', 1);
        $this->assertCount(1, $modelsSimplePaginate);
        $simpleItems = iterator_to_array($modelsSimplePaginate->items());
        $this->assertSame('Alex Rivera', $simpleItems[0]->name);

        $models = $this->SearchableUsers->search('example')->orderBy('name', 'desc')->take(1)->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);

        $models = $this->SearchableUsers->search('example')->orderByDesc('name')->take(1)->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);
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
     * @return void
     */
    public function testSoftDeletedRowsAreExcludedWhenSoftDeleteEnabled(): void
    {
        Configure::write('Explorator.soft_delete', true);

        $taylor = $this->SearchableUsers->find()->where(['email' => 'blake@example.test'])->firstOrFail();
        $this->assertSame(1, $taylor->get('id'));
        $taylor->set('deleted', new DateTime());
        $this->SearchableUsers->saveOrFail($taylor);

        $models = $this->SearchableUsers->search('example')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $models->first()->name);
    }

    /**
     * @return void
     */
    public function testWithTrashedIncludesSoftDeletedRows(): void
    {
        Configure::write('Explorator.soft_delete', true);

        $taylor = $this->SearchableUsers->find()->where(['email' => 'blake@example.test'])->firstOrFail();
        $taylor->set('deleted', new DateTime());

        $this->SearchableUsers->saveOrFail($taylor);

        $models = $this->SearchableUsers->search('example')->withTrashed()->get();
        $this->assertCount(2, $models);
    }

    /**
     * @return void
     */
    public function testOnlyTrashedReturnsSoftDeletedRowsOnly(): void
    {
        Configure::write('Explorator.soft_delete', true);

        $taylor = $this->SearchableUsers->find()->where(['email' => 'blake@example.test'])->firstOrFail();
        $taylor->set('deleted', new DateTime());

        $this->SearchableUsers->saveOrFail($taylor);

        $models = $this->SearchableUsers->search('example')->onlyTrashed()->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);
    }

    /**
     * @return void
     */
    public function testPrefixSearchUsesPrefixPattern(): void
    {
        $table = new SearchableUsersTable([
            'alias' => 'AttributeSearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $table->setEntityClass(AttributeSearchableUser::class);
        $this->getTableLocator()->set('AttributeSearchableUsers', $table);

        $models = $table->search('blake@')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Blake Torres', $models->first()->name);

        $models = $table->search('alex@')->get();
        $this->assertCount(1, $models);
        $this->assertSame('Alex Rivera', $models->first()->name);

        $models = $table->search('@missing.test')->get();
        $this->assertCount(0, $models);
    }

    /**
     * @return void
     */
    public function testItUsesExploratorQuery(): void
    {
        $connection = ConnectionManager::get('test');

        $chirps = new ChirpsTable([
            'alias' => 'Chirps',
            'table' => 'chirps',
            'connection' => $connection,
        ]);
        $this->getTableLocator()->set('Chirps', $chirps);

        $bookmarks = new BookmarksTable([
            'alias' => 'Bookmarks',
            'table' => 'bookmarks',
            'connection' => $connection,
        ]);
        $this->getTableLocator()->set('Bookmarks', $bookmarks);
        SearchableBehavior::enableSyncingFor('Bookmarks');

        $chirp = $chirps->saveOrFail($chirps->newEntity([
            'content' => 'This chirp is searchable',
            'explorator_id' => 'chirp-bookmark-1',
            'created' => new DateTime(),
        ]));
        $bookmarks->saveOrFail($bookmarks->newEntity([
            'label' => 'sample-label',
            'chirp_id' => $chirp->id,
            'created' => new DateTime(),
        ]));

        $models = $bookmarks->search('chirp')->get();
        $this->assertCount(1, $models);
        $this->assertSame('sample-label', $models->first()->label);
        $this->assertSame('This chirp is searchable', $models->first()->chirp->content);
    }

    /**
     * Custom pageName is accepted but unused (Cake PaginatedResultSet has no Illuminate url()).
     *
     * @return void
     */
    public function testItCanPaginateUsingACustomPageArgument(): void
    {
        $page1 = $this->SearchableUsers->search('example')->paginate(1, 'custom', 1);
        $this->assertSame(1, $page1->currentPage());
        $this->assertCount(1, iterator_to_array($page1->items()));

        $page2 = $this->SearchableUsers->search('example')->paginate(1, 'custom', 2);
        $this->assertSame(2, $page2->currentPage());
        $this->assertCount(1, iterator_to_array($page2->items()));
    }

    /**
     * @return void
     */
    public function testItCanSimplePaginateUsingACustomPageArgument(): void
    {
        $page1 = $this->SearchableUsers->search('example')->simplePaginate(1, 'custom', 1);
        $this->assertSame(1, $page1->currentPage());
        $this->assertCount(1, iterator_to_array($page1->items()));
        $this->assertTrue($page1->hasNextPage());

        $page2 = $this->SearchableUsers->search('example')->simplePaginate(1, 'custom', 2);
        $this->assertSame(2, $page2->currentPage());
        $this->assertCount(1, iterator_to_array($page2->items()));
        $this->assertFalse($page2->hasNextPage());
    }

    /**
     * @param list<array<string, string>> $users User rows
     * @return void
     */
    protected function resetUsers(array $users): void
    {
        foreach ($users as $user) {
            $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity([
                'name' => $user['name'],
                'email' => $user['email'],
                'created' => new DateTime(),
            ]));
        }
    }
}
