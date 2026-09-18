<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Job;

use Cake\Cache\Cache;
use Cake\Queue\QueueManager;
use Cake\Queue\TestSuite\QueueTrait;
use Cake\Queue\TestSuite\TestQueueClient;
use Crustum\Explorator\Explorator;
use Crustum\Explorator\Job\MakeSearchableJob;
use Crustum\Explorator\Job\MakeSearchableUniquelyJob;
use Crustum\Explorator\Job\RemoveFromSearchJob;
use Crustum\Explorator\Job\RemoveFromSearchUniquelyJob;
use Crustum\Explorator\Test\Feature\FeatureTestCase;

/**
 * Push-level tests for unique jobs.
 *
 * Exercises the real CakePHP Queue deduplication (`$shouldBeUnique` with a
 * `uniqueCache` queue config) through `Explorator::push()`.
 */
class UniqueJobsTest extends FeatureTestCase
{
    use QueueTrait;

    /**
     * @var string
     */
    protected const UNIQUE_CONFIG = 'unique_test';

    /**
     * @var string
     */
    protected const UNIQUE_CACHE_KEY = 'Cake/Queue.queueUnique.unique_test';

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        // Drop leftover configs so QueueTrait setup of the next test can
        // reconfigure the queue (Cache::setConfig refuses duplicates).
        Cache::drop(static::UNIQUE_CACHE_KEY);
        QueueManager::drop(static::UNIQUE_CONFIG);
        parent::tearDown();
    }

    /**
     * @return void
     */
    protected function configureUniqueQueue(): void
    {
        Cache::drop(static::UNIQUE_CACHE_KEY);
        QueueManager::drop(static::UNIQUE_CONFIG);
        QueueManager::setConfig(static::UNIQUE_CONFIG, [
            'url' => 'null:',
            'uniqueCache' => ['className' => 'Array'],
        ]);
        // Capture the new config with the test transport. This re-runs
        // setConfig, so the cache key is dropped first (Cache::setConfig
        // refuses duplicates).
        Cache::drop(static::UNIQUE_CACHE_KEY);
        TestQueueClient::replaceAllClients();
    }

    /**
     * @param class-string $class Job class
     * @param array<string, mixed> $data Job payload
     * @return void
     */
    protected function pushUnique(string $class, array $data): void
    {
        Explorator::push($class, $data, ['config' => static::UNIQUE_CONFIG]);
    }

    /**
     * @return void
     */
    public function testUniqueMakeSearchableJobDeduplicatesIdenticalPayloads(): void
    {
        $this->configureUniqueQueue();

        $data = ['source' => 'SearchableUsers', 'ids' => [1, 2]];
        $this->pushUnique(MakeSearchableUniquelyJob::class, $data);
        $this->pushUnique(MakeSearchableUniquelyJob::class, $data);

        $this->assertJobQueuedTimes(MakeSearchableUniquelyJob::class, 1);
    }

    /**
     * @return void
     */
    public function testUniqueMakeSearchableJobDeduplicatesReorderedIds(): void
    {
        $this->configureUniqueQueue();

        $this->pushUnique(MakeSearchableUniquelyJob::class, ['source' => 'SearchableUsers', 'ids' => [1, 2]]);
        $this->pushUnique(MakeSearchableUniquelyJob::class, ['source' => 'SearchableUsers', 'ids' => [2, 1]]);

        $this->assertJobQueuedTimes(MakeSearchableUniquelyJob::class, 1);
        $this->assertJobQueuedWith(MakeSearchableUniquelyJob::class, [
            'source' => 'SearchableUsers',
            'ids' => [1, 2],
        ]);
    }

    /**
     * @return void
     */
    public function testUniqueMakeSearchableJobQueuesDifferentPayloads(): void
    {
        $this->configureUniqueQueue();

        $this->pushUnique(MakeSearchableUniquelyJob::class, ['source' => 'SearchableUsers', 'ids' => [1, 2]]);
        $this->pushUnique(MakeSearchableUniquelyJob::class, ['source' => 'SearchableUsers', 'ids' => [3]]);

        $this->assertJobQueuedTimes(MakeSearchableUniquelyJob::class, 2);
    }

    /**
     * @return void
     */
    public function testBaseMakeSearchableJobDoesNotDeduplicate(): void
    {
        $this->configureUniqueQueue();

        $data = ['source' => 'SearchableUsers', 'ids' => [1, 2]];
        $this->pushUnique(MakeSearchableJob::class, $data);
        $this->pushUnique(MakeSearchableJob::class, $data);

        $this->assertJobQueuedTimes(MakeSearchableJob::class, 2);
    }

    /**
     * @return void
     */
    public function testUniqueRemoveFromSearchJobDeduplicatesIdenticalPayloads(): void
    {
        $this->configureUniqueQueue();

        $data = ['source' => 'SearchableUsers', 'ids' => [1, 2]];
        $this->pushUnique(RemoveFromSearchUniquelyJob::class, $data);
        $this->pushUnique(RemoveFromSearchUniquelyJob::class, $data);

        $this->assertJobQueuedTimes(RemoveFromSearchUniquelyJob::class, 1);
    }

    /**
     * @return void
     */
    public function testUniqueRemoveFromSearchJobDeduplicatesReorderedIds(): void
    {
        $this->configureUniqueQueue();

        $this->pushUnique(RemoveFromSearchUniquelyJob::class, ['source' => 'SearchableUsers', 'ids' => [1, 2]]);
        $this->pushUnique(RemoveFromSearchUniquelyJob::class, ['source' => 'SearchableUsers', 'ids' => [2, 1]]);

        $this->assertJobQueuedTimes(RemoveFromSearchUniquelyJob::class, 1);
    }

    /**
     * @return void
     */
    public function testBaseRemoveFromSearchJobDoesNotDeduplicate(): void
    {
        $this->configureUniqueQueue();

        $data = ['source' => 'SearchableUsers', 'ids' => [1, 2]];
        $this->pushUnique(RemoveFromSearchJob::class, $data);
        $this->pushUnique(RemoveFromSearchJob::class, $data);

        $this->assertJobQueuedTimes(RemoveFromSearchJob::class, 2);
    }
}
