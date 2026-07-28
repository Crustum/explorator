<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Searchable users fixture (schema from tests/schema.php).
 */
class SearchableUsersFixture extends TestFixture
{
    /**
     * @var string
     */
    public string $table = 'searchable_users';

    /**
     * @var array<array<string, mixed>>
     */
    public array $records = [];
}
