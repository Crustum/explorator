<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Datasource\ConnectionManager;
use Crustum\Explorator\Engine\DatabaseEngine;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use ReflectionMethod;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Unit tests for DatabaseEngine semantic / vector search support detection.
 */
class DatabaseEngineSemanticTest extends FeatureTestCase
{
    /**
     * @return void
     */
    public function testSupportsVectorSearchIsFalseWithoutConfiguration(): void
    {
        if (!class_exists(DatabaseEngine::class)) {
            $this->markTestSkipped('Database engine not available');
        }

        $engine = new DatabaseEngine();

        $config = ConnectionManager::getConfig('test');
        $this->skipIf(str_contains($config['driver'], 'Postgres'), 'Postgres supports vector search');

        $table = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);

        $method = new ReflectionMethod(DatabaseEngine::class, 'supportsVectorSearch');

        $this->assertFalse($method->invoke($engine, $table));
    }

    /**
     * @return void
     */
    public function testUpdateDoesNotRequireEmbeddingsWithoutConfiguration(): void
    {
        if (!class_exists(DatabaseEngine::class)) {
            $this->markTestSkipped('Database engine not available');
        }

        $engine = new DatabaseEngine();

        $table = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $table);

        $entity = $table->saveOrFail($table->newEntity([
            'name' => 'Vectorless',
            'email' => 'vectorless@example.test',
        ]));

        $engine->update([$entity]);

        $this->assertTrue(true);
    }
}
