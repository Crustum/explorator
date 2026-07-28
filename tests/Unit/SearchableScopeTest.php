<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\Engines\Engine;
use Mockery as m;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Unit tests for Trait importSearchable (replaces SearchableScope macros).
 */
class SearchableScopeTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Explorator.SearchableUsers',
    ];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Explorator.queue', false);
        Configure::write('Explorator.driver', 'null');
        Configure::write('Explorator.chunk.searchable', 2);
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
     * @return void
     */
    public function testImportSearchableChunksAndUpdatesEngine(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('update')->twice();

        $table = new class ([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]) extends SearchableUsersTable {
            /**
             * @inheritDoc
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_scope_engine'];
            }
        };
        $GLOBALS['explorator_scope_engine'] = $engine;
        $this->getTableLocator()->set('SearchableUsers', $table);

        $table->saveOrFail($table->newEntity([
            'name' => 'A',
            'email' => 'a@example.com',
            'created' => new DateTime(),
        ]));
        $table->saveOrFail($table->newEntity([
            'name' => 'B',
            'email' => 'b@example.com',
            'created' => new DateTime(),
        ]));
        $table->saveOrFail($table->newEntity([
            'name' => 'C',
            'email' => 'c@example.com',
            'created' => new DateTime(),
        ]));

        $table->importSearchable(chunk: 2);

        unset($GLOBALS['explorator_scope_engine']);
    }

    /**
     * @return void
     */
    public function testFlushSearchableChunksAndDeletesFromEngine(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('delete')->once();

        $table = new class ([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]) extends SearchableUsersTable {
            /**
             * @inheritDoc
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_scope_engine'];
            }
        };
        $GLOBALS['explorator_scope_engine'] = $engine;
        $this->getTableLocator()->set('SearchableUsers', $table);

        $table->saveOrFail($table->newEntity([
            'name' => 'A',
            'email' => 'flush-a@example.com',
            'created' => new DateTime(),
        ]));

        $table->flushSearchable(chunk: 500);

        unset($GLOBALS['explorator_scope_engine']);
    }
}
