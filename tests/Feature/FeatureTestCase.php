<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * Feature base — Cake fixtures truncate searchable_users / chirps / bookmarks per test.
 */
abstract class FeatureTestCase extends TestCase
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
