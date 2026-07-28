<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use Faker\Generator;

/**
 * Test factory for searchable users (workbench SearchableUserFactory).
 *
 * Uses vierge-noire/cakephp-fixture-factories (require-dev only).
 */
class SearchableUserFactory extends BaseFactory
{
    /**
     * @return string
     */
    protected function getRootTableRegistryName(): string
    {
        return 'SearchableUsers';
    }

    /**
     * @return void
     */
    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(fn(Generator $faker): array => [
            'name' => $faker->name(),
            'email' => $faker->unique()->safeEmail(),
            'age' => $faker->numberBetween(18, 90),
            'deleted' => null,
        ]);
    }

    /**
     * Unverified-style email domain used by Integration SearchableTests.
     *
     * @return $this
     */
    public function unverified()
    {
        return $this->patchData([
            'email' => $this->getFaker()->unique()->userName() . '@unverified.test',
        ]);
    }
}
