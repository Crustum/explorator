<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Jobs;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\Queue\Job\Message;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Job\RemovableExploratorCollection;
use Crustum\Explorator\Job\RemoveFromSearch;
use Crustum\Explorator\Job\RemoveFromSearchUniquely;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Interop\Queue\Processor;
use Mockery as m;
use TestApp\Model\Entity\Chirp;
use TestApp\Model\Entity\SearchableUser;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for RemoveFromSearch job.
 */
class RemoveFromSearchTest extends FeatureTestCase
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
    public function testExecuteDeletesFromEngine(): void
    {
        Configure::write('Explorator.driver', 'null');
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('delete')->once();
        $table = $this->bindTableWithEngine($engine);

        $entity = $table->saveOrFail($table->newEntity([
            'name' => 'Remove User',
            'email' => 'remove@example.com',
            'created' => new DateTime(),
        ]));

        $message = m::mock(Message::class);
        $message->shouldReceive('getArgument')->with('source', '')->andReturn('SearchableUsers');
        $message->shouldReceive('getArgument')->with('ids', [])->andReturn([$entity->id]);

        $this->assertSame(Processor::ACK, (new RemoveFromSearch())->execute($message));
    }

    /**
     * @return void
     */
    public function testExecuteAcksEmptyIds(): void
    {
        $message = m::mock(Message::class);
        $message->shouldReceive('getArgument')->with('source', '')->andReturn('SearchableUsers');
        $message->shouldReceive('getArgument')->with('ids', [])->andReturn([]);

        $this->assertSame(Processor::ACK, (new RemoveFromSearch())->execute($message));
    }

    /**
     * @return void
     */
    public function testRemovableCollectionRoundTripPreservesExploratorKeys(): void
    {
        $entity = new SearchableUser(['id' => 1234, 'name' => 'A']);
        $entity->setSource('SearchableUsers');

        $collection = unserialize(serialize(RemovableExploratorCollection::fromEntities([$entity])));

        $this->assertSame([1234], $collection->exploratorKeys());
        $this->assertSame('SearchableUsers', $collection->first()['source']);
    }

    /**
     * @return void
     */
    public function testRemovableCollectionRoundTripPreservesCustomExploratorKey(): void
    {
        $entity = new Chirp(['id' => 1, 'explorator_id' => 'custom-key.9', 'content' => 'Hi']);
        $entity->setSource('Chirps');

        $collection = unserialize(serialize(RemovableExploratorCollection::fromEntities([$entity])));

        $this->assertSame(['custom-key.9'], $collection->exploratorKeys());
        $this->assertSame('explorator_id', $entity->getExploratorKeyName());
    }

    /**
     * @return void
     */
    public function testUniqueIdIsBasedOnTheClassAndExploratorKeys(): void
    {
        $expected = md5(RemoveFromSearchUniquely::class . ':SearchableUsers:' . json_encode([1, 2]));

        $this->assertSame($expected, (new RemoveFromSearchUniquely())->uniqueId('SearchableUsers', [2, 1]));
    }

    /**
     * @return void
     */
    public function testUniqueIdIsNotAffectedByOrder(): void
    {
        $job = new RemoveFromSearchUniquely();

        $this->assertSame(
            $job->uniqueId('SearchableUsers', [1, 2, 3]),
            $job->uniqueId('SearchableUsers', [3, 1, 2]),
        );
    }

    /**
     * @return void
     */
    public function testExploratorJobOptionsAreSetFromConfig(): void
    {
        Configure::write('Explorator.jobs.tries', 3);
        Configure::write('Explorator.jobs.maxExceptions', 2);

        $job = new class extends RemoveFromSearch {
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
}
