<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Test factory for searchable users (workbench SearchableUserFactory).
 *
 * Uses dereuromark/cakephp-fixture-factories (require-dev only).
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
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator Generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->name(),
            'email' => $generator->unique()->safeEmail(),
            'age' => $generator->numberBetween(18, 90),
            'deleted' => null,
        ];
    }

    /**
     * Unverified-style email domain used by Integration SearchableTests.
     *
     * @return \CakephpFixtureFactories\Factory\BaseFactory
     */
    public function unverified(): BaseFactory
    {
        return $this->state([
            'email' => $this->getGenerator()->unique()->userName() . '@unverified.test',
        ]);
    }
}
