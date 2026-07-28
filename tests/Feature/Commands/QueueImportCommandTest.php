<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Commands;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Queue\TestSuite\QueueTrait;
use Crustum\Explorator\Command\QueueImportCommand;
use Crustum\Explorator\Exception\ExploratorException;
use Crustum\Explorator\Job\MakeRangeSearchable;
use Crustum\Explorator\Test\Feature\FeatureTestCase;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Feature tests for explorator queue-import command.
 */
class QueueImportCommandTest extends FeatureTestCase
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

        Configure::write('Explorator.chunk.searchable', 500);

        $this->SearchableUsers = $this->getTableLocator()->get('SearchableUsers');
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
     * @param array<string, mixed> $options Command options
     * @return array{0: int|null, 1: string}
     */
    protected function runCommand(array $options = []): array
    {
        $command = new QueueImportCommand();
        $args = new Arguments(['SearchableUsers'], $options, ['table']);
        $out = new StubConsoleOutput();
        $err = new StubConsoleOutput();
        $io = new ConsoleIo($out, $err);

        return [$command->execute($args, $io), implode("\n", array_merge($out->messages(), $err->messages()))];
    }

    /**
     * @param callable(array<string, mixed>): bool $callback Job matcher
     * @return bool
     */
    protected function hasQueuedMakeRange(callable $callback): bool
    {
        return array_any(
            $this->getQueuedJobsByClass(MakeRangeSearchable::class),
            $callback,
        );
    }

    /**
     * @return void
     */
    public function testRejectsInvalidOrder(): void
    {
        $this->createUsers(3);
        [$code, $output] = $this->runCommand(['order' => 'sideways']);

        $this->assertSame(QueueImportCommand::CODE_ERROR, $code);
        $this->assertStringContainsString('asc" or "desc"', $output);
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testEmptyTableSucceedsWithoutQueueing(): void
    {
        [$code, $output] = $this->runCommand(['order' => 'asc']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('No records found', $output);
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testProcessesModelsWithRecords(): void
    {
        $this->createUsers(5);
        [$code, $output] = $this->runCommand();

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('models up to ID: 5', $output);
        $this->assertStringContainsString('records have been queued', $output);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 1);
    }

    /**
     * @return void
     */
    public function testUsesCustomChunkSize(): void
    {
        $this->createUsers(10);
        [$code, $output] = $this->runCommand(['chunk' => '2']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('models up to ID: 2', $output);
        $this->assertStringContainsString('models up to ID: 10', $output);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 5);
    }

    /**
     * @return void
     */
    public function testQueuesRangesInDescendingOrder(): void
    {
        $this->createUsers(8);
        [$code] = $this->runCommand(['chunk' => '3', 'order' => 'desc']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $ranges = array_map(
            static fn(array $row): array => [$row['data']['start'], $row['data']['end']],
            $this->getQueuedJobsByClass(MakeRangeSearchable::class),
        );
        $this->assertSame([[6, 8], [3, 5], [1, 2]], $ranges);
    }

    /**
     * @return void
     */
    public function testUsesDefaultChunkSizeWhenNotSpecified(): void
    {
        $this->createUsers(3);
        [$code] = $this->runCommand();

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 1);
    }

    /**
     * @return void
     */
    public function testProcessesLargeDatasetWithChunking(): void
    {
        $this->createUsers(25);
        [$code] = $this->runCommand(['chunk' => '10']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 3);
    }

    /**
     * @return void
     */
    public function testHandlesSingleRecord(): void
    {
        $this->createUsers(1);
        [$code, $output] = $this->runCommand();

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('models up to ID: 1', $output);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 1);
    }

    /**
     * @return void
     */
    public function testUsesExploratorChunkConfigWhenNoOptionProvided(): void
    {
        Configure::write('Explorator.chunk.searchable', 3);
        $this->createUsers(7);
        [$code] = $this->runCommand();

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 3);
    }

    /**
     * @return void
     */
    public function testHandlesNonSequentialIds(): void
    {
        $this->createUsers(6);
        $this->SearchableUsers->delete($this->SearchableUsers->get(2));
        $this->SearchableUsers->delete($this->SearchableUsers->get(4));

        [$code, $output] = $this->runCommand();

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('models up to ID: 6', $output);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 1);
    }

    /**
     * @return void
     */
    public function testHandlesChunkSizeLargerThanDataset(): void
    {
        $this->createUsers(3);
        [$code] = $this->runCommand(['chunk' => '10']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 1);
    }

    /**
     * @return void
     */
    public function testHandlesChunkSizeOfOne(): void
    {
        $this->createUsers(3);
        [$code] = $this->runCommand(['chunk' => '1']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 3);
    }

    /**
     * @return void
     */
    public function testDispatchesJobsWithCorrectParameters(): void
    {
        $this->createUsers(5);
        [$code] = $this->runCommand(['chunk' => '3']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedWith(MakeRangeSearchable::class, [
            'source' => 'SearchableUsers',
            'start' => 1,
            'end' => 3,
        ]);
        $this->assertJobQueuedWith(MakeRangeSearchable::class, [
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

        $command = new QueueImportCommand();
        $args = new Arguments(['NotARealTable'], [], ['table']);
        $io = new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput());
        $command->execute($args, $io);
    }

    /**
     * @return void
     */
    public function testHandlesZeroChunkSize(): void
    {
        $this->createUsers(3);
        [$code, $output] = $this->runCommand(['chunk' => '0']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('ID: 3', $output);
        $this->assertJobQueued(MakeRangeSearchable::class);
    }

    /**
     * @return void
     */
    public function testAcceptsCustomMinOption(): void
    {
        $this->createUsers(10);
        [$code] = $this->runCommand(['min' => '5']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedWith(MakeRangeSearchable::class, [
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
        [$code] = $this->runCommand(['max' => '5']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedWith(MakeRangeSearchable::class, [
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
        [$code] = $this->runCommand(['min' => '3', 'max' => '7']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedWith(MakeRangeSearchable::class, [
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
        [$code] = $this->runCommand(['min' => '2', 'max' => '8', 'chunk' => '3']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 3);
    }

    /**
     * @return void
     */
    public function testHandlesAnEmptyIdRange(): void
    {
        $this->createUsers(5);
        [$code, $output] = $this->runCommand(['min' => '5', 'max' => '2']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertStringContainsString('No records found', $output);
        $this->assertNoJobsQueued();
    }

    /**
     * @return void
     */
    public function testHandlesNegativeMinOption(): void
    {
        $this->createUsers(3);
        [$code] = $this->runCommand(['min' => '-5', 'max' => '2']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedWith(MakeRangeSearchable::class, [
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
        [$code] = $this->runCommand(['queue' => 'custom-queue']);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedToQueue('custom-queue', MakeRangeSearchable::class);
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
        [$code] = $this->runCommand([
            'min' => '5',
            'max' => '15',
            'chunk' => '4',
            'queue' => 'custom-queue',
        ]);

        $this->assertSame(QueueImportCommand::CODE_SUCCESS, $code);
        $this->assertJobQueuedTimes(MakeRangeSearchable::class, 3);
        $this->assertJobQueuedToQueue('custom-queue', MakeRangeSearchable::class);
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
}
