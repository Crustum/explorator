<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\Engines\CollectionEngine;
use Crustum\Explorator\Engines\NullEngine;
use Crustum\Explorator\TestSuite\TestEngine;
use InvalidArgumentException;

/**
 * Unit tests for EngineManager driver resolution.
 */
class EngineManagerTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Explorator.driver', 'null');
    }

    /**
     * @return void
     */
    public function testDefaultDriverIsNullWhenUnset(): void
    {
        Configure::delete('Explorator.driver');
        $manager = new EngineManager();

        $this->assertSame('null', $manager->getDefaultDriver());
        $this->assertInstanceOf(NullEngine::class, $manager->engine());
    }

    /**
     * @return void
     */
    public function testCollectionDriver(): void
    {
        Configure::write('Explorator.driver', 'collection');
        $manager = new EngineManager();

        $this->assertInstanceOf(CollectionEngine::class, $manager->engine());
        $this->assertInstanceOf(CollectionEngine::class, $manager->engine('collection'));
    }

    /**
     * @return void
     */
    public function testTestDriver(): void
    {
        Configure::write('Explorator.driver', 'test');
        $manager = new EngineManager();

        $this->assertInstanceOf(TestEngine::class, $manager->engine());
    }

    /**
     * @return void
     */
    public function testExtendRegistersCustomDriver(): void
    {
        $manager = new EngineManager();
        $manager->extend('custom', fn(): NullEngine => new NullEngine());

        $this->assertInstanceOf(NullEngine::class, $manager->engine('custom'));
    }

    /**
     * @return void
     */
    public function testForgetEnginesClearsResolvedDrivers(): void
    {
        $manager = new EngineManager();
        $first = $manager->engine('null');
        $manager->forgetEngines();
        $second = $manager->engine('null');

        $this->assertNotSame($first, $second);
    }

    /**
     * @return void
     */
    public function testUnknownDriverThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EngineManager())->engine('missing-driver');
    }
}
