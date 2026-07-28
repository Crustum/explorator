<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Bookmarks fixture (schema from tests/schema.php). Empty records — truncate only.
 */
class BookmarksFixture extends TestFixture
{
    /**
     * @var string
     */
    public string $table = 'bookmarks';

    /**
     * @var array<array<string, mixed>>
     */
    public array $records = [];
}
