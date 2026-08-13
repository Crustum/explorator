<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite;

use Cake\Core\Configure;
use Crustum\Explorator\TestSuite\Constraint\Explorator\Flushed;
use Crustum\Explorator\TestSuite\Constraint\Explorator\Indexed;
use Crustum\Explorator\TestSuite\Constraint\Explorator\IndexedPayloadContains;
use Crustum\Explorator\TestSuite\Constraint\Explorator\IndexedTimes;
use Crustum\Explorator\TestSuite\Constraint\Explorator\NoSearchPerformed;
use Crustum\Explorator\TestSuite\Constraint\Explorator\NothingWritten;
use Crustum\Explorator\TestSuite\Constraint\Explorator\RemovedFromSearch;
use Crustum\Explorator\TestSuite\Constraint\Explorator\SearchCount;
use Crustum\Explorator\TestSuite\Constraint\Explorator\SearchPerformed;
use Crustum\Explorator\TestSuite\Constraint\Explorator\WriteCount;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Explorator Trait
 *
 * Capture index writes and searches via TestEngine instead of remote engines.
 *
 * Usage:
 * ```
 * class MyTest extends TestCase
 * {
 *     use ExploratorTrait;
 *
 *     public function testIndexed(): void
 *     {
 *         $table->save($entity);
 *         $this->assertIndexed('SearchableUsers', $entity->id);
 *     }
 * }
 * ```
 *
 * Queue/job asserts belong to cakephp/queue `QueueTrait` — not this trait.
 */
trait ExploratorTrait
{
    /**
     * Driver name before the trait forced `test`.
     *
     * @var mixed
     */
    private mixed $exploratorPreviousDriver = null;

    /**
     * Force the test recording engine and clear captures.
     *
     * @return void
     */
    #[Before]
    public function setupTestEngine(): void
    {
        $this->exploratorPreviousDriver = Configure::read('Explorator.driver');
        Configure::write('Explorator.driver', 'test');
        TestEngine::clearOperations();
        TestEngine::clearSearchResults();
    }

    /**
     * Clear captures and restore the previous Explorator driver.
     *
     * @return void
     */
    #[After]
    public function cleanupExploratorTrait(): void
    {
        TestEngine::clearOperations();
        TestEngine::clearSearchResults();

        if ($this->exploratorPreviousDriver === null) {
            Configure::delete('Explorator.driver');
        } else {
            Configure::write('Explorator.driver', $this->exploratorPreviousDriver);
        }
    }

    /**
     * Assert entities were indexed (engine update) for a table.
     *
     * @param string $table Table alias / entity source
     * @param mixed|null $key Optional Explorator key that must appear in the update
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertIndexed(string $table, mixed $key = null, string $message = ''): void
    {
        $other = $key === null ? $table : ['table' => $table, 'key' => $key];
        $this->assertThat($other, new Indexed(), $message);
    }

    /**
     * Alias of assertIndexed.
     *
     * @param string $table Table alias / entity source
     * @param mixed|null $key Optional Explorator key
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertUpdatedInSearch(string $table, mixed $key = null, string $message = ''): void
    {
        $this->assertIndexed($table, $key, $message);
    }

    /**
     * Assert an indexed update at a specific captured operation index.
     *
     * @param int $at Operation index (0-based in full capture log)
     * @param string $table Table alias
     * @param mixed|null $key Optional Explorator key
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertIndexedAt(int $at, string $table, mixed $key = null, string $message = ''): void
    {
        $other = $key === null ? $table : ['table' => $table, 'key' => $key];
        $this->assertThat($other, new Indexed($at), $message);
    }

    /**
     * Assert update count for a table (optionally filtered by key).
     *
     * @param string $table Table alias
     * @param int $times Expected update count
     * @param mixed|null $key Optional Explorator key filter
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertIndexedTimes(string $table, int $times, mixed $key = null, string $message = ''): void
    {
        $other = $key === null ? $table : ['table' => $table, 'key' => $key];
        $this->assertThat($other, new IndexedTimes($times), $message);
    }

    /**
     * Assert no update was recorded for a table (optionally for a key).
     *
     * @param string $table Table alias
     * @param mixed|null $key Optional Explorator key
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertNotIndexed(string $table, mixed $key = null, string $message = ''): void
    {
        $updates = TestEngine::getUpdatesForTable($table);
        if ($key !== null) {
            $updates = array_values(array_filter(
                $updates,
                static fn(array $row): bool => in_array($key, $row['keys'] ?? [], false),
            ));
        }

        $this->assertEmpty(
            $updates,
            $message ?: sprintf('Table %s was indexed unexpectedly', $table),
        );
    }

    /**
     * Assert entities were removed from search for a table.
     *
     * @param string $table Table alias
     * @param mixed|null $key Optional Explorator key
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertRemovedFromSearch(string $table, mixed $key = null, string $message = ''): void
    {
        $other = $key === null ? $table : ['table' => $table, 'key' => $key];
        $this->assertThat($other, new RemovedFromSearch(), $message);
    }

    /**
     * Assert the search index was flushed for a table.
     *
     * @param string $table Table alias
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertFlushed(string $table, string $message = ''): void
    {
        $this->assertThat($table, new Flushed(), $message);
    }

    /**
     * Assert no update/delete/flush operations were captured.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertNothingWritten(string $message = ''): void
    {
        $this->assertThat(null, new NothingWritten(), $message);
    }

    /**
     * Assert total write operation count (update + delete + flush).
     *
     * @param int $count Expected write count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertWriteCount(int $count, string $message = ''): void
    {
        $this->assertThat($count, new WriteCount(), $message);
    }

    /**
     * Assert an indexed document payload contains a key/value.
     *
     * Soft-delete updates typically include `__soft_deleted` => 1 in the payload.
     *
     * @param string $table Table alias
     * @param string $key Payload key
     * @param mixed $value Expected value (loose ==)
     * @param mixed|null $exploratorKey Optional entity key filter
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertIndexedPayloadContains(
        string $table,
        string $key,
        mixed $value,
        mixed $exploratorKey = null,
        string $message = '',
    ): void {
        $this->assertThat(
            [
                'table' => $table,
                'key' => $key,
                'value' => $value,
                'exploratorKey' => $exploratorKey,
            ],
            new IndexedPayloadContains(),
            $message,
        );
    }

    /**
     * Assert a search or paginate was performed.
     *
     * @param string|null $query Optional exact query string
     * @param string|null $table Optional table alias
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertSearchPerformed(?string $query = null, ?string $table = null, string $message = ''): void
    {
        if ($query === null && $table === null) {
            $this->assertThat(null, new SearchPerformed(), $message);

            return;
        }

        $other = [];
        if ($query !== null) {
            $other['query'] = $query;
        }

        if ($table !== null) {
            $other['table'] = $table;
        }

        $this->assertThat($other, new SearchPerformed(), $message);
    }

    /**
     * Assert no search/paginate was recorded.
     *
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertSearchNotPerformed(string $message = ''): void
    {
        $this->assertThat(null, new NoSearchPerformed(), $message);
    }

    /**
     * Assert search/paginate operation count.
     *
     * @param int $count Expected count
     * @param string $message Optional assertion message
     * @return void
     */
    public function assertSearchCount(int $count, string $message = ''): void
    {
        $this->assertThat($count, new SearchCount(), $message);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExploratorOperations(): array
    {
        return TestEngine::getOperations();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExploratorWrites(): array
    {
        return TestEngine::getWrites();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getExploratorSearches(): array
    {
        return TestEngine::getSearches();
    }

    /**
     * @param string $table Table alias
     * @return list<array<string, mixed>>
     */
    public function getExploratorOperationsForTable(string $table): array
    {
        return TestEngine::getOperationsForTable($table);
    }
}
