<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Test factory for bookmarks (workbench BookmarkFactory).
 *
 * Uses dereuromark/cakephp-fixture-factories (require-dev only).
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
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator Generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'label' => $generator->word(),
        ];
    }

    /**
     * Default associated chirp (chirp_id is NOT NULL).
     *
     * @return static
     */
    protected function configure(): static
    {
        return $this->with('Chirps', ChirpFactory::new());
    }

    /**
     * Associated chirp.
     *
     * @param mixed $parameter Association data
     * @param int $n Number of chirps
     * @return $this
     */
    public function withChirp(mixed $parameter = null, int $n = 1): static
    {
        return $this->with('Chirps', ChirpFactory::new($parameter ?? [], $n));
    }
}
