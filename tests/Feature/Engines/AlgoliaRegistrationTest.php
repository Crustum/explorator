<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature\Engines;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Cake\Core\Configure;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\Engines\Algolia4Engine;
use Crustum\Explorator\Test\Feature\FeatureTestCase;

/**
 * Feature: EngineManager registers Algolia when client exists.
 */
class AlgoliaRegistrationTest extends FeatureTestCase
{
    /**
     * @return void
     */
    public function testAlgoliaDriverResolvesWhenClientPresent(): void
    {
        if (!class_exists(SearchClient::class)) {
            $this->markTestSkipped('Algolia client not installed');
        }

        Configure::write('Explorator.algolia', ['id' => 'test', 'secret' => 'test']);
        $engine = (new EngineManager())->engine('algolia');
        $this->assertInstanceOf(Algolia4Engine::class, $engine);
    }
}
