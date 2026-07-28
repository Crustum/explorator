<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Chirps fixture (schema from tests/schema.php). Empty records — truncate only.
 */
class ChirpsFixture extends TestFixture
{
    /**
     * @var string
     */
    public string $table = 'chirps';

    /**
     * @var array<array<string, mixed>>
     */
    public array $records = [];
}
