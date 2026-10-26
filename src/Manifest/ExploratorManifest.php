<?php
declare(strict_types=1);

namespace Crustum\Explorator\Manifest;

use Crustum\Explorator\ExploratorPlugin;
use Crustum\PluginManifest\Manifest\ManifestOps;

/**
 * Standalone manifest provider for the Explorator plugin.
 *
 * Plain class without interface or trait dependencies, so the plugin loads
 * and runs without `crustum/plugin-manifest` installed. Discovered by the
 * installer through the provider convention when the plugin class itself
 * provides no manifest. All package references stay inside method bodies,
 * which only execute when the installer (and therefore the package) exists.
 */
final class ExploratorManifest
{
    /**
     * Plugin install assets via crustum/plugin-manifest.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__, 2);
        $plugin = ManifestOps::pluginName(ExploratorPlugin::class);

        return array_merge(
            ManifestOps::manifestConfig(
                $plugin,
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'explorator.php',
                CONFIG . 'explorator.php',
                false,
            ),
            ManifestOps::manifestBootstrapAppend(
                $plugin,
                "if (file_exists(CONFIG . 'explorator.php')) {\n    Configure::load('explorator', 'default');\n}",
                '// Explorator Plugin Configuration',
            ),
            ManifestOps::manifestStarRepo($plugin, 'Crustum/explorator'),
        );
    }
}
