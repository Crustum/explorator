<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Jobs;

use Cake\TestSuite\TestCase;
use Crustum\Explorator\Job\RemovableExploratorCollection;
use TestApp\Model\Entity\Chirp;
use TestApp\Model\Entity\SearchableUser;

/**
 * Feature tests for RemovableExploratorCollection.
 */
class RemovableExploratorCollectionTest extends TestCase
{
    /**
     * @return void
     */
    public function testFromEntitiesExtractsExploratorKeys(): void
    {
        $entity = new SearchableUser(['id' => 5, 'name' => 'A']);
        $entity->setSource('SearchableUsers');

        $collection = RemovableExploratorCollection::fromEntities([$entity]);

        $this->assertSame([5], $collection->exploratorKeys());
    }

    /**
     * @return void
     */
    public function testGetQueueableIds(): void
    {
        $one = new SearchableUser(['id' => 1, 'name' => 'A']);
        $one->setSource('SearchableUsers');

        $two = new SearchableUser(['id' => 2, 'name' => 'B']);
        $two->setSource('SearchableUsers');

        $collection = RemovableExploratorCollection::fromEntities([$one, $two]);

        $this->assertSame([1, 2], $collection->exploratorKeys());
    }

    /**
     * @return void
     */
    public function testGetQueueableIdsResolvesCustomExploratorKeys(): void
    {
        $entities = [];
        foreach (['custom-key.1', 'custom-key.2', 'custom-key.3', 'custom-key.4'] as $key) {
            $entity = new Chirp(['explorator_id' => $key, 'content' => 'x']);
            $entity->setSource('Chirps');
            $entities[] = $entity;
        }

        $collection = RemovableExploratorCollection::fromEntities($entities);

        $this->assertSame([
            'custom-key.1',
            'custom-key.2',
            'custom-key.3',
            'custom-key.4',
        ], $collection->exploratorKeys());
    }

    /**
     * @return void
     */
    public function testRemovableExploratorCollectionReturnsExploratorKeys(): void
    {
        $chirpOne = new Chirp(['explorator_id' => '1234', 'content' => 'a']);
        $chirpOne->setSource('Chirps');

        $chirpTwo = new Chirp(['explorator_id' => '2345', 'content' => 'b']);
        $chirpTwo->setSource('Chirps');

        $userOne = new SearchableUser(['id' => 3456, 'name' => 'C']);
        $userOne->setSource('SearchableUsers');

        $userTwo = new SearchableUser(['id' => 7891, 'name' => 'D']);
        $userTwo->setSource('SearchableUsers');

        $collection = RemovableExploratorCollection::fromEntities([
            $chirpOne,
            $chirpTwo,
            $userOne,
            $userTwo,
        ]);

        $this->assertSame(['1234', '2345', 3456, 7891], $collection->exploratorKeys());
    }
}
