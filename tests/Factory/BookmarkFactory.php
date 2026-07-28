<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use Faker\Generator;

/**
 * Test factory for bookmarks (workbench BookmarkFactory).
 *
 * Uses vierge-noire/cakephp-fixture-factories (require-dev only).
 */
class BookmarkFactory extends BaseFactory
{
    /**
     * @return string
     */
    protected function getRootTableRegistryName(): string
    {
        return 'Bookmarks';
    }

    /**
     * @return void
     */
    protected function setDefaultTemplate(): void
    {
        $this->setDefaultData(fn(Generator $faker): array => [
            'label' => $faker->word(),
        ])
            ->withChirp();
    }

    /**
     * Associated chirp.
     *
     * @param mixed $parameter Association data
     * @param int $n Number of chirps
     * @return $this
     */
    public function withChirp(mixed $parameter = null, int $n = 1)
    {
        return $this->with('Chirps', ChirpFactory::make($parameter ?? [], $n));
    }
}
