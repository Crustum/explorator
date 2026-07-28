<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Cake\Queue\TestSuite\QueueTrait;
use Crustum\Explorator\Builder;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Explorator;
use Crustum\Explorator\Job\MakeSearchable;
use Crustum\Explorator\Job\RemoveFromSearch;
use Crustum\Explorator\SearchableIndexer;
use Mockery as m;
use TestApp\Model\Entity\SearchableUser;
use TestApp\Model\Entity\SoftDeleteSearchableUser;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for searchable indexing.
 */
class SearchableTest extends FeatureTestCase
{
    use QueueTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Explorator.queue', false);
        Configure::write('Explorator.driver', 'null');
        Explorator::makeSearchableUsing(MakeSearchable::class);
        Explorator::removeFromSearchUsing(RemoveFromSearch::class);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Explorator::makeSearchableUsing(MakeSearchable::class);
        Explorator::removeFromSearchUsing(RemoveFromSearch::class);
        unset($GLOBALS['explorator_test_engine']);
        m::close();
        parent::tearDown();
    }

    /**
     * @param \Crustum\Explorator\Engines\Engine $engine Engine
     * @return \TestApp\Model\Entity\SearchableUser
     */
    protected function entityUsing(Engine $engine): SearchableUser
    {
        $GLOBALS['explorator_test_engine'] = $engine;

        return new class extends SearchableUser {
            /**
             * @return \Crustum\Explorator\Engines\Engine
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_test_engine'];
            }
        };
    }

    /**
     * @return void
     */
    public function testMakeSearchableSyncCallsEngineUpdate(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('update')->once();
        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($engine);

        (new SearchableIndexer($manager))->makeSearchableSync([$this->entityUsing($engine)]);
    }

    /**
     * @return void
     */
    public function testMakeSearchableQueuedCallsEngineUpdatePathViaJobPayload(): void
    {
        Configure::write('Explorator.queue', true);
        $engine = m::mock(Engine::class);
        $manager = m::mock(EngineManager::class);
        $entity = $this->entityUsing($engine);
        $entity->setSource('SearchableUsers');
        $entity->set('id', 9);

        (new SearchableIndexer($manager))->makeSearchable([$entity]);

        $this->assertJobQueuedWith(MakeSearchable::class, [
            'source' => 'SearchableUsers',
            'ids' => [9],
        ]);
    }

    /**
     * @return void
     */
    public function testMakeSearchableSyncSkipsEmptyCollection(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldNotReceive('update');

        $manager = m::mock(EngineManager::class);

        (new SearchableIndexer($manager))->makeSearchableSync([]);
    }

    /**
     * @return void
     */
    public function testMakeSearchableQueuedSkipsEmptyCollection(): void
    {
        Configure::write('Explorator.queue', true);
        $manager = m::mock(EngineManager::class);

        (new SearchableIndexer($manager))->makeSearchable([]);
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testOverriddenMakeSearchableIsDispatched(): void
    {
        Configure::write('Explorator.queue', true);
        $custom = new class implements JobInterface {
            /**
             * @param \Cake\Queue\Job\Message $message Message
             * @return string|null
             */
            public function execute(Message $message): ?string
            {
                return null;
            }
        };
        $customClass = $custom::class;
        Explorator::makeSearchableUsing($customClass);

        $engine = m::mock(Engine::class);
        $manager = m::mock(EngineManager::class);
        $entity = $this->entityUsing($engine);
        $entity->setSource('SearchableUsers');
        $entity->set('id', 1);

        (new SearchableIndexer($manager))->makeSearchable([$entity]);
        $this->assertJobQueuedTimes($customClass, 1);
    }

    /**
     * @return void
     */
    public function testRemoveFromSearchSyncCallsEngineDelete(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('delete')->once();
        $manager = m::mock(EngineManager::class);

        (new SearchableIndexer($manager))->removeFromSearchSync([$this->entityUsing($engine)]);
    }

    /**
     * @return void
     */
    public function testRemoveFromSearchQueuedPushesJob(): void
    {
        Configure::write('Explorator.queue', true);
        $engine = m::mock(Engine::class);
        $manager = m::mock(EngineManager::class);
        $entity = $this->entityUsing($engine);
        $entity->setSource('SearchableUsers');
        $entity->set('id', 4);

        (new SearchableIndexer($manager))->removeFromSearch([$entity]);
        $this->assertJobQueuedWith(RemoveFromSearch::class, [
            'source' => 'SearchableUsers',
            'ids' => [4],
        ]);
    }

    /**
     * @return void
     */
    public function testRemoveFromSearchSyncSkipsEmptyCollection(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldNotReceive('delete');

        $manager = m::mock(EngineManager::class);

        (new SearchableIndexer($manager))->removeFromSearchSync([]);
    }

    /**
     * @return void
     */
    public function testRemoveFromSearchQueuedSkipsEmptyCollection(): void
    {
        Configure::write('Explorator.queue', true);
        $manager = m::mock(EngineManager::class);

        (new SearchableIndexer($manager))->removeFromSearch([]);
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testOverriddenRemoveFromSearchIsDispatched(): void
    {
        Configure::write('Explorator.queue', true);
        $custom = new class implements JobInterface {
            /**
             * @param \Cake\Queue\Job\Message $message Message
             * @return string|null
             */
            public function execute(Message $message): ?string
            {
                return null;
            }
        };
        $customClass = $custom::class;
        Explorator::removeFromSearchUsing($customClass);

        $engine = m::mock(Engine::class);
        $manager = m::mock(EngineManager::class);
        $entity = $this->entityUsing($engine);
        $entity->setSource('SearchableUsers');
        $entity->set('id', 2);

        (new SearchableIndexer($manager))->removeFromSearch([$entity]);
        $this->assertJobQueuedTimes($customClass, 1);
    }

    /**
     * @return void
     */
    public function testWasSearchableOnEntityWithoutSoftDeletes(): void
    {
        $entity = new SearchableUser();
        $this->assertTrue($entity->wasSearchableBeforeUpdate());
        $this->assertTrue($entity->wasSearchableBeforeDelete());
        $this->assertTrue($entity->searchIndexShouldBeUpdated());
    }

    /**
     * @return void
     */
    public function testWasSearchableBeforeUpdateWorksFromTrueToFalse(): void
    {
        $entity = new SoftDeleteSearchableUser();
        $entity->set('published_at', new DateTime(), ['asOriginal' => true]);
        $entity->set('deleted', null, ['asOriginal' => true]);
        $entity->setNew(false);

        $entity->set('published_at');

        $this->assertTrue($entity->wasSearchableBeforeUpdate());
        $this->assertFalse($entity->shouldBeSearchable());
    }

    /**
     * @return void
     */
    public function testWasSearchableBeforeDeleteWorksWhenDeleting(): void
    {
        $entity = new SoftDeleteSearchableUser();
        $entity->set('published_at', new DateTime(), ['asOriginal' => true]);
        $entity->set('deleted', null, ['asOriginal' => true]);
        $entity->setNew(false);

        $entity->set('deleted', new DateTime());

        $this->assertTrue($entity->wasSearchableBeforeDelete());
        $this->assertFalse($entity->shouldBeSearchable());
    }

    /**
     * @return void
     */
    public function testItQueriesSearchableModelsByTheirIds(): void
    {
        $table = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $table);

        $one = $table->saveOrFail($table->newEntity([
            'name' => 'One',
            'email' => 'one@example.com',
            'created' => new DateTime(),
        ]));
        $two = $table->saveOrFail($table->newEntity([
            'name' => 'Two',
            'email' => 'two@example.com',
            'created' => new DateTime(),
        ]));

        $models = $table->getExploratorModelsByIds(new Builder($table, 'q'), [$one->id, $two->id]);
        $this->assertSame([$one->id, $two->id], $models->extract('id')->toList());
    }

    /**
     * @return void
     */
    public function testImportSearchableOrdersByExploratorKey(): void
    {
        $table = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $table);

        $table->saveOrFail($table->newEntity([
            'name' => 'B',
            'email' => 'b@example.com',
            'created' => new DateTime(),
        ]));
        $table->saveOrFail($table->newEntity([
            'name' => 'A',
            'email' => 'a@example.com',
            'created' => new DateTime(),
        ]));

        $engine = m::mock(Engine::class);
        $seen = [];
        $engine->shouldReceive('update')->once()->andReturnUsing(function (iterable $entities) use (&$seen): void {
            foreach ($entities as $entity) {
                $seen[] = $entity->id;
            }
        });

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($engine);
        $GLOBALS['explorator_test_engine'] = $engine;

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
                return $GLOBALS['explorator_test_engine'];
            }
        };
        $this->getTableLocator()->set('SearchableUsers', $table);

        (new SearchableIndexer($manager))->importSearchable($table, chunk: 10);
        $this->assertCount(2, $seen);
        $this->assertTrue($seen[0] < $seen[1]);
    }

    /**
     * @return void
     */
    public function testQueueMakeSearchableUsesConfiguredJobClass(): void
    {
        $this->assertSame(MakeSearchable::class, Explorator::$makeSearchableJob);
    }
}
