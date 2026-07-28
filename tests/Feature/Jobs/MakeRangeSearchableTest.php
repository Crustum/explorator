<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Jobs;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\Queue\Job\Message;
use Cake\Queue\TestSuite\QueueTrait;
use Crustum\Explorator\Explorator;
use Crustum\Explorator\Job\MakeRangeSearchable;
use Crustum\Explorator\Job\MakeSearchable;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Interop\Queue\Processor;
use Mockery as m;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for MakeRangeSearchable.
 */
class MakeRangeSearchableTest extends FeatureTestCase
{
    use QueueTrait;

    /**
     * @var \TestApp\Model\Table\SearchableUsersTable
     */
    protected SearchableUsersTable $SearchableUsers;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->SearchableUsers = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
        ]);
        $this->getTableLocator()->set('SearchableUsers', $this->SearchableUsers);
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
     * @param int $count Record count
     * @return void
     */
    protected function createUsers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity([
                'name' => 'User ' . ($i + 1),
                'email' => 'user' . ($i + 1) . '@example.com',
                'created' => new DateTime(),
            ]));
        }
    }

    /**
     * @param int $start Start id
     * @param int $end End id
     * @return string|null
     */
    protected function executeRange(int $start, int $end): ?string
    {
        $message = m::mock(Message::class);
        $message->shouldReceive('getArgument')->with('source', '')->andReturn('SearchableUsers');
        $message->shouldReceive('getArgument')->with('start', 0)->andReturn($start);
        $message->shouldReceive('getArgument')->with('end', 0)->andReturn($end);

        return (new MakeRangeSearchable())->execute($message);
    }

    /**
     * @return void
     */
    public function testExecuteAcksEmptyRange(): void
    {
        $message = m::mock(Message::class);
        $message->shouldReceive('getArgument')->with('source', '')->andReturn('');
        $message->shouldReceive('getArgument')->with('start', 0)->andReturn(0);
        $message->shouldReceive('getArgument')->with('end', 0)->andReturn(0);

        $this->assertSame(Processor::ACK, (new MakeRangeSearchable())->execute($message));
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testHandleMakesModelsInRangeSearchable(): void
    {
        $this->createUsers(5);

        $this->assertSame(Processor::ACK, $this->executeRange(2, 4));
        $this->assertJobQueuedWith(Explorator::$makeSearchableJob, [
            'source' => 'SearchableUsers',
            'ids' => [2, 3, 4],
        ]);
        $this->assertJobQueuedTimes(Explorator::$makeSearchableJob, 1);
    }

    /**
     * @return void
     */
    public function testHandleWithSingleIdRange(): void
    {
        $this->createUsers(3);

        $this->assertSame(Processor::ACK, $this->executeRange(2, 2));
        $this->assertJobQueuedWith(MakeSearchable::class, [
            'source' => 'SearchableUsers',
            'ids' => [2],
        ]);
        $this->assertJobQueuedTimes(MakeSearchable::class, 1);
    }

    /**
     * @return void
     */
    public function testHandleWithNoModelsInRange(): void
    {
        $this->createUsers(3);

        $this->assertSame(Processor::ACK, $this->executeRange(10, 15));
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testHandleWithGapsInIds(): void
    {
        $this->createUsers(5);
        $this->SearchableUsers->delete($this->SearchableUsers->get(2));
        $this->SearchableUsers->delete($this->SearchableUsers->get(4));

        $this->assertSame(Processor::ACK, $this->executeRange(1, 5));
        $this->assertJobQueuedWith(MakeSearchable::class, [
            'source' => 'SearchableUsers',
            'ids' => [1, 3, 5],
        ]);
        $this->assertJobQueuedTimes(MakeSearchable::class, 1);
    }

    /**
     * @return void
     */
    public function testHandleWithReverseRangeThrowsNoError(): void
    {
        $this->createUsers(3);

        $this->assertSame(Processor::ACK, $this->executeRange(5, 2));
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testHandleWithZeroRange(): void
    {
        $this->createUsers(3);

        $this->assertSame(Processor::ACK, $this->executeRange(0, 2));
        $this->assertJobQueuedTimes(MakeSearchable::class, 1);
    }

    /**
     * @return void
     */
    public function testHandleWithVeryLargeRange(): void
    {
        $this->createUsers(5);

        $this->assertSame(Processor::ACK, $this->executeRange(1, 1000000));
        $this->assertJobQueuedWith(MakeSearchable::class, [
            'source' => 'SearchableUsers',
            'ids' => [1, 2, 3, 4, 5],
        ]);
        $this->assertJobQueuedTimes(MakeSearchable::class, 1);
    }
}
