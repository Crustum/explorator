<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Model\Behavior\SearchableBehavior;
use Mockery as m;
use TestApp\Model\Entity\ObserverSearchableUser;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for SearchableBehavior (ported from ModelObserverTest).
 */
class ModelObserverTest extends FeatureTestCase
{
    /**
     * @var \TestApp\Model\Table\SearchableUsersTable
     */
    protected SearchableUsersTable $SearchableUsers;

    /**
     * @var \Mockery\MockInterface&\Crustum\Explorator\Engines\Engine
     */
    protected Engine $engine;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Configure::write('Explorator.driver', 'null');
        Configure::write('Explorator.queue', false);
        Configure::write('Explorator.after_commit', false);
        Configure::write('Explorator.soft_delete', false);

        $this->engine = m::mock(Engine::class);
        $GLOBALS['explorator_observer_engine'] = $this->engine;

        $this->SearchableUsers = new class ([
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
                $this->setEntityClass(ObserverSearchableUser::class);
                $this->addBehavior('Crustum/Explorator.Searchable');
            }

            /**
             * @inheritDoc
             */
            public function searchableUsing(): Engine
            {
                return $GLOBALS['explorator_observer_engine'];
            }
        };

        $this->getTableLocator()->set('SearchableUsers', $this->SearchableUsers);

        SearchableBehavior::enableSyncingFor('SearchableUsers');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['explorator_observer_engine'], $GLOBALS['user.shouldBeSearchable']);
        unset($GLOBALS['user.searchIndexShouldBeUpdated'], $GLOBALS['user.wasSearchableBeforeDelete']);
        unset($GLOBALS['user.wasSearchableBeforeUpdate']);
        SearchableBehavior::enableSyncingFor('SearchableUsers');
        m::close();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testSavedHandlerMakesModelSearchable(): void
    {
        $this->engine->shouldReceive('update')->once();

        $entity = $this->SearchableUsers->newEntity([
            'name' => 'Sample',
            'email' => 'sample@example.test',
            'created' => new DateTime(),
        ]);
        $this->SearchableUsers->saveOrFail($entity);
    }

    /**
     * @return void
     */
    public function testSavedHandlerDoesntMakeModelSearchableWhenSearchShouldntUpdate(): void
    {
        $GLOBALS['user.searchIndexShouldBeUpdated'] = false;
        $this->engine->shouldNotReceive('update');

        $entity = $this->SearchableUsers->newEntity([
            'name' => 'Sample',
            'email' => 'email2@example.com',
            'created' => new DateTime(),
        ]);
        $this->SearchableUsers->saveOrFail($entity);
    }

    /**
     * @return void
     */
    public function testSavedHandlerDoesntMakeModelSearchableWhenDisabled(): void
    {
        SearchableBehavior::disableSyncingFor('SearchableUsers');
        $this->engine->shouldNotReceive('update');

        $entity = $this->SearchableUsers->newEntity([
            'name' => 'Sample',
            'email' => 'email3@example.com',
            'created' => new DateTime(),
        ]);
        $this->SearchableUsers->saveOrFail($entity);
    }

    /**
     * @return void
     */
    public function testSavedHandlerMakesModelUnsearchableWhenDisabledPerModelRule(): void
    {
        $GLOBALS['user.shouldBeSearchable'] = false;
        $this->engine->shouldReceive('delete')->once();
        $this->engine->shouldNotReceive('update');

        $entity = $this->SearchableUsers->newEntity([
            'name' => 'Sample',
            'email' => 'email4@example.com',
            'created' => new DateTime(),
        ]);
        $this->SearchableUsers->saveOrFail($entity);
    }

    /**
     * @return void
     */
    public function testDeletedHandlerMakesModelUnsearchable(): void
    {
        $GLOBALS['user.wasSearchableBeforeDelete'] = true;
        $this->engine->shouldReceive('update')->once();
        $this->engine->shouldReceive('delete')->once();

        $entity = $this->SearchableUsers->newEntity([
            'name' => 'Sample',
            'email' => 'email5@example.com',
            'created' => new DateTime(),
        ]);
        $this->SearchableUsers->saveOrFail($entity);
        $this->SearchableUsers->delete($entity);
    }

    /**
     * @return void
     */
    public function testDeletedHandlerDoesntMakeModelUnsearchableWhenAlreadyUnsearchable(): void
    {
        $GLOBALS['user.wasSearchableBeforeDelete'] = false;
        $this->engine->shouldReceive('update')->once();
        $this->engine->shouldNotReceive('delete');

        $entity = $this->SearchableUsers->newEntity([
            'name' => 'Sample',
            'email' => 'email6@example.com',
            'created' => new DateTime(),
        ]);
        $this->SearchableUsers->saveOrFail($entity);
        $this->SearchableUsers->delete($entity);
    }
}
