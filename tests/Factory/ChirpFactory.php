<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use Faker\Generator;

/**
 * Test factory for chirps (workbench ChirpFactory).
 *
 * Uses vierge-noire/cakephp-fixture-factories (require-dev only).
 */
class ChirpFactory extends BaseFactory
{
    /**
     * @return string
     */
    protected function getRootTableRegistryName(): string
    {
        return 'Chirps';
    }

    /**
     * @return void
     */
    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(fn(Generator $faker): array => [
            'content' => $faker->text(),
            'explorator_id' => $faker->uuid(),
            'deleted' => null,
        ]);
    }
}
