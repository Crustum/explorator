<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Queue\Job\Message;
use Cake\Queue\QueueManager;
use Cake\Queue\TestSuite\QueueTrait;
use Crustum\Explorator\Command\QueueImportCommand;
use Crustum\Explorator\Exception\ExploratorException;
use Crustum\Explorator\Job\MakeRangeSearchableJob;
use Crustum\Explorator\Job\MakeSearchableJob;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use Mockery as m;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for explorator queue-import command.
 */
class QueueImportCommandTest extends FeatureTestCase
{
    use ConsoleIntegrationTestTrait;
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

        Configure::write('Explorator.chunk.searchable', 500);

        $this->SearchableUsers = $this->getTableLocator()->get('SearchableUsers');
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
     * @param callable(array<string, mixed>): bool $callback Job matcher
     * @return bool
     */
    protected function hasQueuedMakeRange(callable $callback): bool
    {
        return array_any(
            $this->getQueuedJobsByClass(MakeRangeSearchableJob::class),
            $callback,
        );
    }

    /**
     * @return void
     */
    public function testRejectsInvalidOrder(): void
    {
        $this->createUsers(3);
        $this->exec('explorator queue-import SearchableUsers --order=sideways');

        $this->assertExitCode(QueueImportCommand::CODE_ERROR);
        $this->assertErrorContains('asc" or "desc"');
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testEmptyTableSucceedsWithoutQueueing(): void
    {
        $this->exec('explorator queue-import SearchableUsers --order=asc');

        $this->assertExitSuccess();
        $this->assertOutputContains('No records found');
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testProcessesModelsWithRecords(): void
    {
        $this->createUsers(5);
        $this->exec('explorator queue-import SearchableUsers');

        $this->assertExitSuccess();
        $this->assertOutputContains('models up to ID: 5');
        $this->assertOutputContains('records have been queued');
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 1);
    }

    /**
     * @return void
     */
    public function testUsesCustomChunkSize(): void
    {
        $this->createUsers(10);
        $this->exec('explorator queue-import SearchableUsers --chunk=2');

        $this->assertExitSuccess();
        $this->assertOutputContains('models up to ID: 2');
        $this->assertOutputContains('models up to ID: 10');
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 5);
    }

    /**
     * @return void
     */
    public function testQueuesRangesInDescendingOrder(): void
    {
        $this->createUsers(8);
        $this->exec('explorator queue-import SearchableUsers --chunk=3 --order=desc');

        $this->assertExitSuccess();
        $ranges = array_map(
            static fn(array $row): array => [$row['data']['start'], $row['data']['end']],
            $this->getQueuedJobsByClass(MakeRangeSearchableJob::class),
        );
        $this->assertSame([[6, 8], [3, 5], [1, 2]], $ranges);
    }

    /**
     * @return void
     */
    public function testUsesDefaultChunkSizeWhenNotSpecified(): void
    {
        $this->createUsers(3);
        $this->exec('explorator queue-import SearchableUsers');

        $this->assertExitSuccess();
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 1);
    }

    /**
     * @return void
     */
    public function testProcessesLargeDatasetWithChunking(): void
    {
        $this->createUsers(25);
        $this->exec('explorator queue-import SearchableUsers --chunk=10');

        $this->assertExitSuccess();
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 3);
    }

    /**
     * @return void
     */
    public function testHandlesSingleRecord(): void
    {
        $this->createUsers(1);
        $this->exec('explorator queue-import SearchableUsers');

        $this->assertExitSuccess();
        $this->assertOutputContains('models up to ID: 1');
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 1);
    }

    /**
     * @return void
     */
    public function testUsesExploratorChunkConfigWhenNoOptionProvided(): void
    {
        Configure::write('Explorator.chunk.searchable', 3);
        $this->createUsers(7);
        $this->exec('explorator queue-import SearchableUsers');

        $this->assertExitSuccess();
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 3);
    }

    /**
     * @return void
     */
    public function testHandlesNonSequentialIds(): void
    {
        $this->createUsers(6);
        $this->SearchableUsers->delete($this->SearchableUsers->get(2));
        $this->SearchableUsers->delete($this->SearchableUsers->get(4));

        $this->exec('explorator queue-import SearchableUsers');

        $this->assertExitSuccess();
        $this->assertOutputContains('models up to ID: 6');
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 1);
    }

    /**
     * @return void
     */
    public function testHandlesChunkSizeLargerThanDataset(): void
    {
        $this->createUsers(3);
        $this->exec('explorator queue-import SearchableUsers --chunk=10');

        $this->assertExitSuccess();
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 1);
    }

    /**
     * @return void
     */
    public function testHandlesChunkSizeOfOne(): void
    {
        $this->createUsers(3);
        $this->exec('explorator queue-import SearchableUsers --chunk=1');

        $this->assertExitSuccess();
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 3);
    }

    /**
     * @return void
     */
    public function testDispatchesJobsWithCorrectParameters(): void
    {
        $this->createUsers(5);
        $this->exec('explorator queue-import SearchableUsers --chunk=3');

        $this->assertExitSuccess();
        $this->assertJobQueuedWith(MakeRangeSearchableJob::class, [
            'source' => 'SearchableUsers',
            'start' => 1,
            'end' => 3,
        ]);
        $this->assertJobQueuedWith(MakeRangeSearchableJob::class, [
            'source' => 'SearchableUsers',
            'start' => 4,
            'end' => 5,
        ]);
    }

    /**
     * @return void
     */
    public function testHandlesInvalidModelClass(): void
    {
        $this->expectException(ExploratorException::class);

        $this->exec('explorator queue-import NotARealTable');
    }

    /**
     * @return void
     */
    public function testHandlesZeroChunkSize(): void
    {
        $this->createUsers(3);
        $this->exec('explorator queue-import SearchableUsers --chunk=0');

        $this->assertExitSuccess();
        $this->assertOutputContains('ID: 3');
        $this->assertJobQueued(MakeRangeSearchableJob::class);
    }

    /**
     * @return void
     */
    public function testAcceptsCustomMinOption(): void
    {
        $this->createUsers(10);
        $this->exec('explorator queue-import SearchableUsers --min=5');

        $this->assertExitSuccess();
        $this->assertJobQueuedWith(MakeRangeSearchableJob::class, [
            'source' => 'SearchableUsers',
            'start' => 5,
            'end' => 10,
        ]);
    }

    /**
     * @return void
     */
    public function testAcceptsCustomMaxOption(): void
    {
        $this->createUsers(10);
        $this->exec('explorator queue-import SearchableUsers --max=5');

        $this->assertExitSuccess();
        $this->assertJobQueuedWith(MakeRangeSearchableJob::class, [
            'source' => 'SearchableUsers',
            'start' => 1,
            'end' => 5,
        ]);
    }

    /**
     * @return void
     */
    public function testAcceptsBothMinAndMaxOptions(): void
    {
        $this->createUsers(10);
        $this->exec('explorator queue-import SearchableUsers --min=3 --max=7');

        $this->assertExitSuccess();
        $this->assertJobQueuedWith(MakeRangeSearchableJob::class, [
            'source' => 'SearchableUsers',
            'start' => 3,
            'end' => 7,
        ]);
    }

    /**
     * @return void
     */
    public function testChunksCustomRangeCorrectly(): void
    {
        $this->createUsers(10);
        $this->exec('explorator queue-import SearchableUsers --min=2 --max=8 --chunk=3');

        $this->assertExitSuccess();
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 3);
    }

    /**
     * @return void
     */
    public function testHandlesAnEmptyIdRange(): void
    {
        $this->createUsers(5);
        $this->exec('explorator queue-import SearchableUsers --min=5 --max=2');

        $this->assertExitSuccess();
        $this->assertOutputContains('No records found');
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testHandlesNegativeMinOption(): void
    {
        $this->createUsers(3);
        $this->exec('explorator queue-import SearchableUsers --min=-5 --max=2');

        $this->assertExitSuccess();
        $this->assertJobQueuedWith(MakeRangeSearchableJob::class, [
            'source' => 'SearchableUsers',
            'start' => -5,
            'end' => 2,
        ]);
    }

    /**
     * @return void
     */
    public function testAcceptsCustomQueueOption(): void
    {
        $this->createUsers(10);
        $this->exec('explorator queue-import SearchableUsers --queue=custom-queue');

        $this->assertExitSuccess();
        $this->assertJobQueuedToQueue('custom-queue', MakeRangeSearchableJob::class);
        $this->assertTrue($this->hasQueuedMakeRange(
            static fn(array $row): bool => ($row['data']['start'] ?? null) === 1
                && ($row['data']['end'] ?? null) === 10
                && ($row['options']['queue'] ?? null) === 'custom-queue',
        ));
    }

    /**
     * @return void
     */
    public function testCanAcceptAllOptions(): void
    {
        $this->createUsers(20);
        $this->exec('explorator queue-import SearchableUsers --min=5 --max=15 --chunk=4 --queue=custom-queue');

        $this->assertExitSuccess();
        $this->assertJobQueuedTimes(MakeRangeSearchableJob::class, 3);
        $this->assertJobQueuedToQueue('custom-queue', MakeRangeSearchableJob::class);
        $this->assertTrue($this->hasQueuedMakeRange(
            static fn(array $row): bool => ($row['data']['start'] ?? null) === 5
                && ($row['data']['end'] ?? null) === 8
                && ($row['options']['queue'] ?? null) === 'custom-queue',
        ));
        $this->assertTrue($this->hasQueuedMakeRange(
            static fn(array $row): bool => ($row['data']['start'] ?? null) === 9
                && ($row['data']['end'] ?? null) === 12
                && ($row['options']['queue'] ?? null) === 'custom-queue',
        ));
        $this->assertTrue($this->hasQueuedMakeRange(
            static fn(array $row): bool => ($row['data']['start'] ?? null) === 13
                && ($row['data']['end'] ?? null) === 15
                && ($row['options']['queue'] ?? null) === 'custom-queue',
        ));
    }

    /**
     * @return void
     */
    public function testUsesCustomQueueForMakeSearchableJobs(): void
    {
        $this->createUsers(1);

        Configure::write('Explorator.queue', ['connection' => 'sync', 'queue' => 'scout']);
        QueueManager::setConfig('sync', (array)QueueManager::getConfig('default'));
        try {
            $this->exec('explorator queue-import SearchableUsers --queue=custom-queue');

            $this->assertExitSuccess();

            $ranges = $this->getQueuedJobsByClass(MakeRangeSearchableJob::class);
            $this->assertCount(1, $ranges);
            $data = $ranges[0]['data'];

            $message = m::mock(Message::class);
            $message->shouldReceive('getArgument')->with('source', '')->andReturn($data['source']);
            $message->shouldReceive('getArgument')->with('start', 0)->andReturn($data['start']);
            $message->shouldReceive('getArgument')->with('end', 0)->andReturn($data['end']);
            $message->shouldReceive('getArgument')->with('queue', '')->andReturn($data['queue'] ?? '');
            $message->shouldReceive('getArgument')->with('connection', '')->andReturn($data['connection'] ?? '');

            new MakeRangeSearchableJob()->execute($message);

            $this->assertJobQueuedTimes(MakeSearchableJob::class, 1);
            $this->assertJobQueuedToQueue('custom-queue', MakeSearchableJob::class);
        } finally {
            Configure::delete('Explorator.queue');
            QueueManager::drop('sync');
        }
    }
}
