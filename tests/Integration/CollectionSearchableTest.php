<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Integration;

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration: collection searchable end-to-end (no credentials required).
 */
#[Group('collection')]
class CollectionSearchableTest extends IntegrationTestCase
{
    use SearchableTestsTrait;

    /**
     * @inheritDoc
     */
    protected function exploratorDriver(): string
    {
        return 'collection';
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
    }

    /**
     * @return void
     */
    public function testItCanUseBasicSearchToFetchKeys(): void
    {
        $keys = $this->itCanUseBasicSearchToFetchKeys()->toList();
        $this->assertNotEmpty($keys);
        $this->assertContains($this->idForName('Lara North'), $keys);
    }

    /**
     * Collection driver keys() does not apply query callbacks.
     *
     * @return void
     */
    public function testItCanUseBasicSearchWithQueryCallbackToFetchKeys(): void
    {
        $unfiltered = $this->itCanUseBasicSearchToFetchKeys()->toList();
        $filtered = $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys()->toList();
        $this->assertSame($unfiltered, $filtered);
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
