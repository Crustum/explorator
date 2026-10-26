<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Unit\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\Explorator\ExploratorPlugin;
use Crustum\Explorator\Manifest\ExploratorManifest;
use Crustum\PluginManifest\Manifest\ManifestResolver;

/**
 * Covers the standalone manifest provider: identical operations to the
 * former plugin-class manifest, discoverable without the interface.
 */
class ExploratorManifestTest extends TestCase
{
    /**
     * The provider emits config, bootstrap and star operations.
     *
     * @return void
     */
    public function testManifestOperations(): void
    {
        $ops = ExploratorManifest::manifest();
        $tags = array_column($ops, 'tag');

        $this->assertContains('config', $tags);
        $this->assertContains('bootstrap', $tags);
        $this->assertContains('star-repo', $tags);
    }

    /**
     * The resolver finds the provider for the plugin class.
     *
     * @return void
     */
    public function testResolverFindsProvider(): void
    {
        $this->assertSame(
            ExploratorManifest::class,
            ManifestResolver::providerClass(ExploratorPlugin::class),
        );
        $this->assertSame(ExploratorManifest::manifest(), ManifestResolver::manifestFor(ExploratorPlugin::class));
    }

    /**
     * The plugin class itself carries no manifest contract.
     *
     * @return void
     */
    public function testPluginClassHasNoManifestContract(): void
    {
        $this->assertFalse(method_exists(ExploratorPlugin::class, 'manifest'));
    }
}
