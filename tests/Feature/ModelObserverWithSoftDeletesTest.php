<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Model\Behavior\SearchableBehavior;
use Mockery as m;
use TestApp\Model\Entity\ObserverSearchableUser;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for SearchableBehavior + SoftDeleteBehavior
 * (ported from ModelObserverWithSoftDeletesTest).
 */
class ModelObserverWithSoftDeletesTest extends FeatureTestCase
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
        Configure::write('Explorator.soft_delete', true);

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
                $this->addBehavior('Crustum/Explorator.SoftDelete');
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
        unset($GLOBALS['explorator_observer_engine']);
        SearchableBehavior::enableSyncingFor('SearchableUsers');
        m::close();
        parent::tearDown();
    }

    /**
     * @return \TestApp\Model\Entity\ObserverSearchableUser
     */
    protected function createQuietly(array $data = []): ObserverSearchableUser
    {
        /** @var \TestApp\Model\Entity\ObserverSearchableUser $entity */
        $entity = $this->SearchableUsers->withoutSyncingToSearch(function () use ($data): EntityInterface {
            $entity = $this->SearchableUsers->newEntity(array_merge([
                'name' => 'Sample',
                'email' => uniqid('soft-', true) . '@example.com',
                'created' => new DateTime(),
            ], $data));

            return $this->SearchableUsers->saveOrFail($entity);
        });

        return $entity;
    }

    /**
     * @return void
     */
    public function testDeletedHandlerMakesModelUnsearchableWhenItShouldNotBeSearchable(): void
    {
        $entity = $this->createQuietly();

        $this->engine->shouldReceive('delete')->once();

        $this->SearchableUsers->forceDelete($entity);
    }

    /**
     * @return void
     */
    public function testDeletedHandlerMakesModelSearchableWhenItShouldBeSearchable(): void
    {
        $entity = $this->createQuietly();

        $this->engine->shouldReceive('update')->once();

        $this->SearchableUsers->delete($entity);
        $this->assertNotNull($entity->get('deleted'));
    }

    /**
     * @return void
     */
    public function testRestoredHandlerMakesModelSearchable(): void
    {
        $entity = $this->createQuietly([
            'deleted' => new DateTime(),
        ]);

        $this->engine->shouldReceive('update')->twice();

        $this->SearchableUsers->restore($entity);
    }
}
