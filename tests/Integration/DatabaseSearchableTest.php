<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Integration;

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration: database searchable end-to-end (no credentials required).
 */
#[Group('database')]
class DatabaseSearchableTest extends IntegrationTestCase
{
    use SearchableTestsTrait;

    /**
     * @inheritDoc
     */
    protected function exploratorDriver(): string
    {
        return 'database';
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSearchableSuite();
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearch(): void
    {
        $results = $this->itCanUseBasicSearch();
        $names = array_values($this->pluckNameById($results));

        $this->assertContains('Lara North', $names);
        $this->assertContains('Larry Casper', $names);
        $this->assertContains('Amos Larson Sr.', $names);
        $this->assertLessThanOrEqual(10, count($names));
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearchWithQueryCallback(): void
    {
        $results = $this->itCanUseBasicSearchWithQueryCallback();
        foreach ($results as $entity) {
            $this->assertStringEndsWith('@verified.test', (string)$entity->email);
        }

        $names = $this->pluckNameById($results);
        $larryId = $this->idForName('Larry Casper');
        $laraId = $this->idForName('Lara North');
        $this->assertArrayNotHasKey($larryId, $names);
        $this->assertArrayHasKey($laraId, $names);
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearchToFetchKeys(): void
    {
        $keys = $this->itCanUseBasicSearchToFetchKeys()->toList();
        $this->assertNotEmpty($keys);
        $this->assertLessThanOrEqual(10, count($keys));
        $this->assertContains($this->idForName('Lara North'), $keys);
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearchWithQueryCallbackToFetchKeys(): void
    {
        $keys = $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys()->toList();
        $this->assertNotContains($this->idForName('Larry Casper'), $keys);
        $this->assertContains($this->idForName('Lara North'), $keys);
    }

    /**
     * @return void
     */
    public function testItCanUsePaginatedSearch(): void
    {
        [$page1, $page2] = $this->itCanUsePaginatedSearch();
        $this->assertCount(5, collection($page1)->toList());
        $this->assertGreaterThan(0, collection($page2)->count());
    }

    /**
     * @return void
     */
    public function testItCanUsePaginatedSearchWithQueryCallback(): void
    {
        [$page1] = $this->itCanUsePaginatedSearchWithQueryCallback();
        foreach ($page1 as $entity) {
            $this->assertStringEndsWith('@verified.test', (string)$entity->email);
        }
    }
}
