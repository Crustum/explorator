<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Test factory for chirps (workbench ChirpFactory).
 *
 * Uses dereuromark/cakephp-fixture-factories (require-dev only).
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
     * @param \CakephpFixtureFactories\Generator\GeneratorInterface $generator Generator
     * @return array<string, mixed>
     */
    public function definition(GeneratorInterface $generator): array
    {
        return [
            'content' => $generator->text(),
            'explorator_id' => $generator->uuid(),
            'deleted' => null,
        ];
    }
}
