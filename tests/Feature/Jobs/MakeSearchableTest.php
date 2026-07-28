<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Jobs;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\Queue\Job\Message;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Job\MakeSearchable;
use Crustum\Explorator\Job\MakeSearchableUniquely;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Interop\Queue\Processor;
use Mockery as m;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for MakeSearchable job.
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

        $this->assertSame(Processor::ACK, (new MakeSearchable())->execute($message));
    }

    /**
     * @return void
     */
    public function testExecuteAcksEmptyIds(): void
    {
        $message = m::mock(Message::class);
        $message->shouldReceive('getArgument')->with('source', '')->andReturn('SearchableUsers');
        $message->shouldReceive('getArgument')->with('ids', [])->andReturn([]);

        $this->assertSame(Processor::ACK, (new MakeSearchable())->execute($message));
    }

    /**
     * @return void
     */
    public function testUniqueIdIsBasedOnTheClassAndExploratorKeys(): void
    {
        $ids = [2, 1];
        $expected = md5(MakeSearchableUniquely::class . ':SearchableUsers:' . json_encode([1, 2]));

        $this->assertSame($expected, (new MakeSearchableUniquely())->uniqueId('SearchableUsers', $ids));
    }

    /**
     * @return void
     */
    public function testUniqueIdIsNotAffectedByModelOrder(): void
    {
        $job = new MakeSearchableUniquely();

        $this->assertSame(
            $job->uniqueId('SearchableUsers', [1, 2, 3]),
            $job->uniqueId('SearchableUsers', [3, 2, 1]),
        );
    }

    /**
     * @return void
     */
    public function testUniqueIdDiffersForDifferentModels(): void
    {
        $job = new MakeSearchableUniquely();

        $this->assertNotSame(
            $job->uniqueId('SearchableUsers', [1, 2]),
            $job->uniqueId('SearchableUsers', [3, 4]),
        );
    }

    /**
     * @return void
     */
    public function testExploratorJobOptionsAreSetFromConfig(): void
    {
        Configure::write('Explorator.jobs.tries', 3);
        Configure::write('Explorator.jobs.maxExceptions', 2);

        $job = new class extends MakeSearchable {
            /**
             * @return array{tries?: int, maxExceptions?: int}
             */
            public function exposedOptions(): array
            {
                return $this->exploratorJobOptions();
            }
        };

        $this->assertSame(['tries' => 3, 'maxExceptions' => 2], $job->exposedOptions());
    }

    /**
     * @return void
     */
    public function testExploratorJobOptionsAreEmptyWithoutConfig(): void
    {
        Configure::delete('Explorator.jobs.tries');
        Configure::delete('Explorator.jobs.maxExceptions');

        $job = new class extends MakeSearchable {
            /**
             * @return array{tries?: int, maxExceptions?: int}
             */
            public function exposedOptions(): array
            {
                return $this->exploratorJobOptions();
            }
        };

        $this->assertSame([], $job->exposedOptions());
    }
}
