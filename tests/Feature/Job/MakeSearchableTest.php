<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Job;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\Queue\Job\Message;
use Crustum\Explorator\Engine\Engine;
use Crustum\Explorator\Job\MakeSearchableJob;
use Crustum\Explorator\Job\MakeSearchableUniquelyJob;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Interop\Queue\Processor;
use Mockery as m;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for MakeSearchableJob job.
 */
class MakeSearchableTest extends FeatureTestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['explorator_job_engine']);
        m::close();
        parent::tearDown();
    }

    /**
     * @return \TestApp\Model\Table\SearchableUsersTable
     */
    protected function bindTableWithEngine(Engine $engine): SearchableUsersTable
    {
        $GLOBALS['explorator_job_engine'] = $engine;
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
                return $GLOBALS['explorator_job_engine'];
            }
        };
        $this->getTableLocator()->set('SearchableUsers', $table);

        return $table;
    }

    /**
     * @return void
     */
    public function testExecuteUpdatesEngineForIds(): void
    {
        Configure::write('Explorator.driver', 'null');
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('update')->once();
        $table = $this->bindTableWithEngine($engine);

        $entity = $table->saveOrFail($table->newEntity([
            'name' => 'Job User',
            'email' => 'job@example.com',
            'created' => new DateTime(),
        ]));

        $message = m::mock(Message::class);
        $message->shouldReceive('getArgument')->with('source', '')->andReturn('SearchableUsers');
        $message->shouldReceive('getArgument')->with('ids', [])->andReturn([$entity->id]);

        $this->assertSame(Processor::ACK, (new MakeSearchableJob())->execute($message));
    }

    /**
     * @return void
     */
    public function testExecuteAcksEmptyIds(): void
    {
        $message = m::mock(Message::class);
        $message->shouldReceive('getArgument')->with('source', '')->andReturn('SearchableUsers');
        $message->shouldReceive('getArgument')->with('ids', [])->andReturn([]);

        $this->assertSame(Processor::ACK, (new MakeSearchableJob())->execute($message));
    }

    /**
     * @return void
     */
    public function testUniquelyJobIsMarkedShouldBeUnique(): void
    {
        $this->assertTrue(MakeSearchableUniquelyJob::$shouldBeUnique);
        $this->assertInstanceOf(MakeSearchableJob::class, new MakeSearchableUniquelyJob());
    }

    /**
     * @return void
     */
    public function testBaseJobIsNotMarkedShouldBeUnique(): void
    {
        $this->assertFalse(property_exists(MakeSearchableJob::class, 'shouldBeUnique'));
    }
}
