<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Integration;

use Cake\Collection\CollectionInterface;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\Paging\PaginatedInterface;
use Cake\I18n\DateTime;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\Model\Behavior\SearchableBehavior;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Shared helpers for Integration searchable suites.
 */
trait SearchableTestsTrait
{
    /**
     * @var \TestApp\Model\Table\SearchableUsersTable
     */
    protected SearchableUsersTable $SearchableUsers;

    /**
     * Driver name for this suite.
     *
     * @return string
     */
    abstract protected function exploratorDriver(): string;

    /**
     * @return void
     */
    protected function setUpSearchableSuite(): void
    {
        Configure::write('Explorator.driver', $this->exploratorDriver());
        Configure::write('Explorator.queue', false);
        Configure::write('Explorator.soft_delete', false);
        Configure::write('Explorator.wait_for_tasks', true);

        $driver = $this->exploratorDriver();
        $_ENV['user.toSearchableArray'] = function ($entity) use ($driver): array {
            $id = $entity->id;
            $id = $driver === 'typesense' ? (string)$id : (int)$id;

            return [
                'id' => $id,
                'name' => $entity->name,
                'age' => (int)($entity->age ?? 0),
            ];
        };

        $this->SearchableUsers = new SearchableUsersTable([
            'alias' => 'SearchableUsers',
            'table' => 'searchable_users',
            'connection' => ConnectionManager::get('test'),
            'registryAlias' => 'SearchableUsers',
        ]);
        $this->getTableLocator()->set('SearchableUsers', $this->SearchableUsers);
        SearchableBehavior::enableSyncingFor('SearchableUsers');

        $this->SearchableUsers->withoutSyncingToSearch(function (): void {
            $this->seedSearchableUsers();
        });
    }

    /**
     * Seed users whose names match "lar" for search assertions.
     *
     * Verified emails use `@verified.test`; others use `@unverified.test`.
     *
     * @return void
     */
    protected function seedSearchableUsers(): void
    {
        $rows = [
            ['name' => 'Lara North', 'email' => '1@verified.test', 'age' => 25],
            ['name' => 'Example 2', 'email' => '2@verified.test', 'age' => 25],
            ['name' => 'Example 3', 'email' => '3@verified.test', 'age' => 25],
            ['name' => 'Example 4', 'email' => '4@verified.test', 'age' => 25],
            ['name' => 'Example 5', 'email' => '5@verified.test', 'age' => 25],
            ['name' => 'Example 6', 'email' => '6@verified.test', 'age' => 25],
            ['name' => 'Example 7', 'email' => '7@verified.test', 'age' => 25],
            ['name' => 'Example 8', 'email' => '8@verified.test', 'age' => 25],
            ['name' => 'Example 9', 'email' => '9@verified.test', 'age' => 25],
            ['name' => 'Example 10', 'email' => '10@verified.test', 'age' => 25],
            ['name' => 'Larry Casper', 'email' => '11@unverified.test', 'age' => 25],
            ['name' => 'Reta Larkin', 'email' => '12@verified.test', 'age' => 25],
            ['name' => 'Example 13', 'email' => '13@verified.test', 'age' => 25],
            ['name' => 'Example 14', 'email' => '14@verified.test', 'age' => 25],
            ['name' => 'Example 15', 'email' => '15@verified.test', 'age' => 25],
            ['name' => 'Example 16', 'email' => '16@verified.test', 'age' => 25],
            ['name' => 'Example 17', 'email' => '17@verified.test', 'age' => 25],
            ['name' => 'Example 18', 'email' => '18@verified.test', 'age' => 25],
            ['name' => 'Example 19', 'email' => '19@verified.test', 'age' => 25],
            ['name' => 'Prof. Larry Prosacco DVM', 'email' => '20@unverified.test', 'age' => 25],
            ['name' => 'Example 21', 'email' => '21@unverified.test', 'age' => 25],
            ['name' => 'Example 22', 'email' => '22@unverified.test', 'age' => 25],
            ['name' => 'Example 23', 'email' => '23@unverified.test', 'age' => 25],
            ['name' => 'Example 24', 'email' => '24@unverified.test', 'age' => 25],
            ['name' => 'Example 25', 'email' => '25@unverified.test', 'age' => 25],
            ['name' => 'Example 26', 'email' => '26@unverified.test', 'age' => 25],
            ['name' => 'Example 27', 'email' => '27@unverified.test', 'age' => 25],
            ['name' => 'Example 28', 'email' => '28@unverified.test', 'age' => 25],
            ['name' => 'Example 29', 'email' => '29@unverified.test', 'age' => 25],
            ['name' => 'Example 30', 'email' => '30@unverified.test', 'age' => 25],
            ['name' => 'Example 31', 'email' => '31@unverified.test', 'age' => 25],
            ['name' => 'Example 32', 'email' => '32@unverified.test', 'age' => 25],
            ['name' => 'Example 33', 'email' => '33@unverified.test', 'age' => 25],
            ['name' => 'Example 34', 'email' => '34@unverified.test', 'age' => 25],
            ['name' => 'Example 35', 'email' => '35@unverified.test', 'age' => 25],
            ['name' => 'Example 36', 'email' => '36@unverified.test', 'age' => 25],
            ['name' => 'Example 37', 'email' => '37@unverified.test', 'age' => 25],
            ['name' => 'Example 38', 'email' => '38@unverified.test', 'age' => 25],
            ['name' => 'Linkwood Larkin', 'email' => '39@unverified.test', 'age' => 25],
            ['name' => 'Otis Larson MD', 'email' => '40@verified.test', 'age' => 25],
            ['name' => 'Gudrun Larkin', 'email' => '41@verified.test', 'age' => 25],
            ['name' => 'Dax Larkin', 'email' => '42@verified.test', 'age' => 25],
            ['name' => 'Dana Larson Sr.', 'email' => '43@verified.test', 'age' => 25],
            ['name' => 'Amos Larson Sr.', 'email' => '44@verified.test', 'age' => 25],
        ];

        foreach ($rows as $row) {
            $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity(array_merge($row, [
                'created' => new DateTime(),
            ])));
        }
    }

    /**
     * Wait briefly for async search-engine indexing.
     *
     * @return void
     */
    protected function waitForSearchIndex(): void
    {
        $microseconds = match ($this->exploratorDriver()) {
            'algolia' => 1_500_000,
            default => 50_000,
        };

        usleep($microseconds);
    }

    /**
     * Engine-specific index settings before where-comparison asserts (Meili only).
     *
     * @return array<string, mixed>|null
     */
    protected function indexSettingsForWhereComparisons(): ?array
    {
        return match ($this->exploratorDriver()) {
            'meilisearch' => ['filterableAttributes' => ['age']],
            default => null,
        };
    }

    /**
     * @return iterable<\Cake\Datasource\EntityInterface>
     */
    protected function itCanUseBasicSearch(): iterable
    {
        return $this->SearchableUsers->search('lar')->take(10)->get();
    }

    /**
     * @return iterable<\Cake\Datasource\EntityInterface>
     */
    protected function itCanUseBasicSearchWithQueryCallback(): iterable
    {
        return $this->SearchableUsers->search('lar')->take(10)->query(fn($query) => $query->where(['email LIKE' => '%@verified.test']))->get();
    }

    /**
     * @return \Cake\Collection\CollectionInterface
     */
    protected function itCanUseBasicSearchToFetchKeys(): CollectionInterface
    {
        return $this->SearchableUsers->search('lar')->take(10)->keys();
    }

    /**
     * @return \Cake\Collection\CollectionInterface
     */
    protected function itCanUseBasicSearchWithQueryCallbackToFetchKeys(): CollectionInterface
    {
        return $this->SearchableUsers->search('lar')->take(10)->query(fn($query) => $query->where(['email LIKE' => '%@verified.test']))->keys();
    }

    /**
     * @return array{0: \Cake\Datasource\Paging\PaginatedInterface, 1: \Cake\Datasource\Paging\PaginatedInterface}
     */
    protected function itCanUsePaginatedSearch(): array
    {
        return [
            $this->SearchableUsers->search('lar')->take(10)->paginate(5, 'page', 1),
            $this->SearchableUsers->search('lar')->take(10)->paginate(5, 'page', 2),
        ];
    }

    /**
     * @return array{0: \Cake\Datasource\Paging\PaginatedInterface, 1: \Cake\Datasource\Paging\PaginatedInterface}
     */
    protected function itCanUsePaginatedSearchWithQueryCallback(): array
    {
        $callback = (fn($query) => $query->where(['email LIKE' => '%@verified.test']));

        return [
            $this->SearchableUsers->search('lar')->take(10)->query($callback)->paginate(5, 'page', 1),
            $this->SearchableUsers->search('lar')->take(10)->query($callback)->paginate(5, 'page', 2),
        ];
    }

    /**
     * @return \Cake\Datasource\Paging\PaginatedInterface
     */
    protected function itCanUsePaginatedSearchWithEmptyQueryCallback(): PaginatedInterface
    {
        return $this->SearchableUsers->search('*')->query(function ($query): void {
        })->paginate();
    }

    /**
     * @return mixed
     */
    protected function itCanAccessRawSearchResultsOfPaginateUsingAfterRawSearchCallback(): mixed
    {
        $result = null;
        $this->SearchableUsers->search('*')
            ->withRawResults(function ($raw) use (&$result): void {
                $result = $raw;
            })
            ->paginate();

        return $result;
    }

    /**
     * @return mixed
     */
    protected function itCanAccessRawSearchResultsOfPaginateRawUsingAfterRawSearchCallback(): mixed
    {
        $result = null;
        $this->SearchableUsers->search('*')
            ->withRawResults(function ($raw) use (&$result): void {
                $result = $raw;
            })
            ->paginateRaw();

        return $result;
    }

    /**
     * @return mixed
     */
    protected function itCanAccessRawSearchResultsOfSimplePaginateUsingAfterRawSearchCallback(): mixed
    {
        $result = null;
        $this->SearchableUsers->search('*')
            ->withRawResults(function ($raw) use (&$result): void {
                $result = $raw;
            })
            ->simplePaginate();

        return $result;
    }

    /**
     * @return mixed
     */
    protected function itCanAccessRawSearchResultsOfSimplePaginateRawUsingAfterRawSearchCallback(): mixed
    {
        $result = null;
        $this->SearchableUsers->search('*')
            ->withRawResults(function ($raw) use (&$result): void {
                $result = $raw;
            })
            ->simplePaginateRaw();

        return $result;
    }

    /**
     * @return mixed
     */
    protected function itCanAccessRawSearchResultsOfGetUsingAfterRawSearchCallback(): mixed
    {
        $result = null;
        $this->SearchableUsers->search('*')
            ->withRawResults(function ($raw) use (&$result): void {
                $result = $raw;
            })
            ->get();

        return $result;
    }

    /**
     * @return mixed
     */
    protected function itCanAccessRawSearchResultsOfCursorUsingAfterRawSearchCallback(): mixed
    {
        $result = null;
        $this->SearchableUsers->search('*')
            ->withRawResults(function ($raw) use (&$result): void {
                $result = $raw;
            })
            ->cursor();

        return $result;
    }

    /**
     * @return void
     */
    protected function itCanMakeWhereComparisons(): void
    {
        $this->SearchableUsers->deleteAll([]);

        $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity([
            'name' => 'Blake Torres',
            'email' => 'blake@verified.test',
            'age' => 35,
            'created' => new DateTime(),
        ]));
        $this->SearchableUsers->saveOrFail($this->SearchableUsers->newEntity([
            'name' => 'Alex Rivera',
            'email' => 'alex@verified.test',
            'age' => 30,
            'created' => new DateTime(),
        ]));

        $engine = (new EngineManager())->engine($this->exploratorDriver());
        if (method_exists($engine, 'flush')) {
            $engine->flush($this->SearchableUsers);
        }

        $settings = $this->indexSettingsForWhereComparisons();
        if ($settings !== null && method_exists($engine, 'updateIndexSettings')) {
            $engine->updateIndexSettings($this->SearchableUsers->searchableAs(), $settings);
        }

        $this->SearchableUsers->importSearchable();
        $this->waitForSearchIndex();

        $this->assertSame(
            ['Blake Torres'],
            array_values($this->pluckNameById($this->SearchableUsers->search('*')->where('age', '>', 30)->get())),
        );
        $this->assertEqualsCanonicalizing(
            ['Blake Torres', 'Alex Rivera'],
            array_values($this->pluckNameById($this->SearchableUsers->search('*')->where('age', '>=', 30)->get())),
        );
        $this->assertSame(
            ['Alex Rivera'],
            array_values($this->pluckNameById($this->SearchableUsers->search('*')->where('age', '<', 35)->get())),
        );
        $this->assertEqualsCanonicalizing(
            ['Blake Torres', 'Alex Rivera'],
            array_values($this->pluckNameById($this->SearchableUsers->search('*')->where('age', '<=', 35)->get())),
        );
        $this->assertSame(
            ['Alex Rivera'],
            array_values($this->pluckNameById($this->SearchableUsers->search('*')->where('age', '!=', 35)->get())),
        );
        $this->assertSame(
            ['Blake Torres'],
            array_values($this->pluckNameById($this->SearchableUsers->search('*')->where('age', '!=', 30)->get())),
        );
        $this->assertSame(
            ['Blake Torres'],
            array_values($this->pluckNameById(
                $this->SearchableUsers->search('*')->where('age', '>', 30)->where('age', '<', 40)->get(),
            )),
        );
        $this->assertSame(
            ['Alex Rivera'],
            array_values($this->pluckNameById(
                $this->SearchableUsers->search('*')->where('age', '>', 25)->where('age', '<', 35)->get(),
            )),
        );
    }

    /**
     * @param iterable<\Cake\Datasource\EntityInterface> $results Results
     * @return array<int|string, string>
     */
    protected function pluckNameById(iterable $results): array
    {
        $map = [];
        foreach ($results as $entity) {
            $map[$entity->id] = (string)$entity->name;
        }

        return $map;
    }

    /**
     * Current primary key for a seeded user name.
     *
     * @param string $name Display name
     * @return int|string
     */
    protected function idForName(string $name): int|string
    {
        $entity = $this->SearchableUsers->find()
            ->select(['id'])
            ->where(['name' => $name])
            ->enableHydration(true)
            ->firstOrFail();

        return $entity->id;
    }

    /**
     * Build id=>name map from current DB rows in engine ranking order.
     *
     * Do not hardcode primary keys — autoincrement may not start at 1.
     *
     * @param list<string> $namesInRankOrder Names in expected hit order
     * @return array<int|string, string>
     */
    protected function expectedNameMapFromDb(array $namesInRankOrder): array
    {
        $map = [];
        foreach ($namesInRankOrder as $name) {
            $id = $this->idForName($name);
            $map[$id] = $name;
        }

        return $map;
    }

    /**
     * Primary keys for names in ranking order (from DB).
     *
     * @param list<string> $namesInRankOrder Names in expected hit order
     * @param bool $asString String keys (Algolia / Typesense keys())
     * @return list<int|string>
     */
    protected function expectedKeysFromDb(array $namesInRankOrder, bool $asString = false): array
    {
        $ids = array_keys($this->expectedNameMapFromDb($namesInRankOrder));
        if ($asString) {
            return array_map(strval(...), $ids);
        }

        return array_values($ids);
    }
}
