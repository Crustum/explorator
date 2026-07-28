<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature Builder pagination against database engine.
 */
class BuilderTest extends FeatureTestCase
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

        $this->SearchableUsers = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $this->SearchableUsers);

        for ($i = 1; $i <= 20; $i++) {
            $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity([
                'name' => 'Search User ' . $i,
                'email' => "user{$i}@example.com",
                'created' => new DateTime(),
            ]));
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity([
                'name' => 'Other ' . $i,
                'email' => "other{$i}@example.com",
                'created' => new DateTime(),
            ]));
        }
    }

    /**
     * @return void
     */
    public function testItCanPaginateWithoutCustomQueryCallback(): void
    {
        $paginator = $this->SearchableUsers->search('Search User')->paginate(15);

        $this->assertSame(20, $paginator->totalCount());
        $this->assertSame(2, $paginator->pageCount());
        $this->assertSame(15, $paginator->perPage());
    }

    /**
     * @return void
     */
    public function testItCanPaginateWithCustomQueryCallback(): void
    {
        $paginator = $this->SearchableUsers->search('Search User')->query(function ($query): void {
            $query->where(['id <' => 11]);
        })->paginate(15);

        $this->assertSame(10, $paginator->totalCount());
        $this->assertSame(1, $paginator->pageCount());
    }
}
