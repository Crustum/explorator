<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Integration;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Base Integration case — Cake fixtures own table truncate / autoincrement reset.
 */
abstract class IntegrationTestCase extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'plugin.Crustum/Explorator.SearchableUsers',
        'plugin.Crustum/Explorator.Chirps',
        'plugin.Crustum/Explorator.Bookmarks',
    ];
}
