<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit\TestSuite;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Crustum\Explorator\Builder;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\SearchableIndexer;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Crustum\Explorator\TestSuite\ExploratorTrait;
use Crustum\Explorator\TestSuite\TestEngine;
use PHPUnit\Framework\Attributes\UsesTrait;
use TestApp\Model\Entity\SearchableUser;

/**
 * ExploratorTrait assertion coverage (Broadcasting BroadcastingTraitTest analogue).
 */
#[UsesTrait(ExploratorTrait::class)]
class ExploratorTraitTest extends FeatureTestCase
{
    use ExploratorTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Explorator.queue', false);
    }

    /**
     * @param int $id Entity id
     * @param string $name Name
     * @param string $source Entity source / table alias
     * @return \TestApp\Model\Entity\SearchableUser
     */
    protected function makeUser(int $id = 1, string $name = 'Ada', string $source = 'SearchableUsers'): SearchableUser
    {
        $entity = new SearchableUser([
            'id' => $id,
            'name' => $name,
            'email' => 'ada@example.com',
            'age' => 30,
            'created' => new DateTime(),
        ]);
        $entity->setSource($source);
        $entity->setNew(false);

        return $entity;
    }

    /**
     * @return void
     */
    public function testAssertIndexed(): void
    {
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$this->makeUser(9)]);

        $this->assertIndexed('SearchableUsers');
        $this->assertIndexed('SearchableUsers', 9);
    }

    /**
     * @return void
     */
    public function testAssertUpdatedInSearch(): void
    {
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$this->makeUser(9)]);

        $this->assertUpdatedInSearch('SearchableUsers', 9);
    }

    /**
     * @return void
     */
    public function testAssertNotIndexed(): void
    {
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$this->makeUser(1)]);

        $this->assertNotIndexed('Chirps');
        $this->assertNotIndexed('SearchableUsers', 99);
    }

    /**
     * @return void
     */
    public function testAssertIndexedTimes(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1)]);
        $indexer->makeSearchableSync([$this->makeUser(2)]);
        $indexer->makeSearchableSync([$this->makeUser(3)]);

        $this->assertIndexedTimes('SearchableUsers', 3);
    }

    /**
     * @return void
     */
    public function testAssertIndexedAt(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1, 'A')]);
        $indexer->removeFromSearchSync([$this->makeUser(2)]);
        $indexer->makeSearchableSync([$this->makeUser(3, 'C')]);

        $this->assertIndexedAt(0, 'SearchableUsers', 1);
        $this->assertIndexedAt(2, 'SearchableUsers', 3);
    }

    /**
     * @return void
     */
    public function testAssertRemovedFromSearch(): void
    {
        (new SearchableIndexer(new EngineManager()))->removeFromSearchSync([$this->makeUser(4)]);

        $this->assertRemovedFromSearch('SearchableUsers', 4);
    }

    /**
     * @return void
     */
    public function testAssertFlushed(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        (new EngineManager())->engine()->flush($table);

        $this->assertFlushed('SearchableUsers');
    }

    /**
     * @return void
     */
    public function testAssertNothingWritten(): void
    {
        $this->assertNothingWritten();
    }

    /**
     * @return void
     */
    public function testAssertWriteCount(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1)]);
        $indexer->removeFromSearchSync([$this->makeUser(2)]);
        (new EngineManager())->engine()->flush($this->getTableLocator()->get('SearchableUsers'));

        $this->assertWriteCount(3);
    }

    /**
     * @return void
     */
    public function testAssertIndexedPayloadContains(): void
    {
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([
            $this->makeUser(9, 'Grace'),
        ]);

        $this->assertIndexedPayloadContains('SearchableUsers', 'name', 'Grace', 9);
        $this->assertIndexedPayloadContains('SearchableUsers', 'email', 'ada@example.com');
    }

    /**
     * @return void
     */
    public function testAssertSearchPerformed(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        (new EngineManager())->engine()->search(new Builder($table, 'example'));

        $this->assertSearchPerformed();
        $this->assertSearchPerformed('example');
        $this->assertSearchPerformed('example', 'SearchableUsers');
    }

    /**
     * @return void
     */
    public function testAssertSearchNotPerformed(): void
    {
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$this->makeUser(1)]);

        $this->assertSearchNotPerformed();
    }

    /**
     * @return void
     */
    public function testAssertSearchCount(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        $engine = (new EngineManager())->engine();
        $builder = new Builder($table, 'example');
        $engine->search($builder);
        $engine->paginate($builder, 15, 1);
        $engine->search($builder);

        $this->assertSearchCount(3);
    }

    /**
     * @return void
     */
    public function testGetExploratorOperations(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1)]);
        $indexer->removeFromSearchSync([$this->makeUser(2)]);

        $operations = $this->getExploratorOperations();

        $this->assertCount(2, $operations);
        $this->assertSame('update', $operations[0]['operation']);
        $this->assertSame('delete', $operations[1]['operation']);
    }

    /**
     * @return void
     */
    public function testGetExploratorWrites(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        $engine = (new EngineManager())->engine();
        $engine->search(new Builder($table, 'q'));
        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$this->makeUser(1)]);

        $this->assertCount(1, $this->getExploratorWrites());
    }

    /**
     * @return void
     */
    public function testGetExploratorSearches(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        (new EngineManager())->engine()->search(new Builder($table, 'q'));
        (new EngineManager())->engine()->paginate(new Builder($table, 'q'), 10, 1);

        $this->assertCount(2, $this->getExploratorSearches());
    }

    /**
     * @return void
     */
    public function testGetExploratorOperationsForTable(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1, 'A', 'SearchableUsers')]);
        $indexer->makeSearchableSync([$this->makeUser(2, 'B', 'Chirps')]);
        $indexer->makeSearchableSync([$this->makeUser(3, 'C', 'SearchableUsers')]);

        $this->assertCount(2, $this->getExploratorOperationsForTable('SearchableUsers'));
    }

    /**
     * @return void
     */
    public function testTraitLifecycle(): void
    {
        $this->assertCount(0, $this->getExploratorOperations());

        (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$this->makeUser(1)]);

        $this->assertCount(1, $this->getExploratorOperations());
    }

    /**
     * @return void
     */
    public function testMultipleUpdatesSameTable(): void
    {
        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->makeSearchableSync([$this->makeUser(1, 'One')]);
        $indexer->makeSearchableSync([$this->makeUser(2, 'Two')]);
        $indexer->makeSearchableSync([$this->makeUser(3, 'Three')]);

        $operations = $this->getExploratorOperationsForTable('SearchableUsers');

        $this->assertCount(3, $operations);
        $this->assertSame('One', $operations[0]['payloads'][0]['name']);
        $this->assertSame('Two', $operations[1]['payloads'][0]['name']);
        $this->assertSame('Three', $operations[2]['payloads'][0]['name']);
    }

    /**
     * @return void
     */
    public function testIndexedWithComplexPayload(): void
    {
        $entity = $this->makeUser(123, 'Complex');
        $_ENV['user.toSearchableArray'] = static fn(SearchableUser $user): array => [
            'id' => $user->id,
            'profile' => [
                'name' => $user->name,
                'tags' => ['a', 'b'],
            ],
            'meta' => ['score' => 9.5],
        ];

        try {
            (new SearchableIndexer(new EngineManager()))->makeSearchableSync([$entity]);

            $operations = $this->getExploratorOperations();
            $payload = $operations[0]['payloads'][0];

            $this->assertSame([
                'id' => 123,
                'profile' => [
                    'name' => 'Complex',
                    'tags' => ['a', 'b'],
                ],
                'meta' => ['score' => 9.5],
            ], $payload);
            $this->assertIndexedPayloadContains('SearchableUsers', 'profile', $payload['profile']);
            $this->assertIndexedPayloadContains('SearchableUsers', 'meta', $payload['meta']);
        } finally {
            unset($_ENV['user.toSearchableArray']);
        }
    }

    /**
     * @return void
     */
    public function testNothingWrittenIgnoresSearches(): void
    {
        $table = $this->getTableLocator()->get('SearchableUsers');
        (new EngineManager())->engine()->search(new Builder($table, 'only-search'));

        $this->assertNothingWritten();
        $this->assertSearchCount(1);
    }

    /**
     * @return void
     */
    public function testClearOperationsIsolationBetweenTests(): void
    {
        $this->assertSame([], TestEngine::getOperations());
    }
}
