<?php
declare(strict_types=1);

namespace Crustum\Explorator;

use Cake\Core\BasePlugin;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Crustum\Explorator\Engines\CollectionEngine;
use Crustum\Explorator\Engines\DatabaseEngine;
use Crustum\Explorator\Engines\NullEngine;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;
use Override;

/**
 * CakePHP Explorator plugin — driver-based search for Tables and Entities.
 *
 * @uses \Crustum\PluginManifest\Manifest\ManifestTrait
 */
class ExploratorPlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    /**
     * Plugin name.
     *
     * @var string|null
     */
    protected ?string $name = 'Explorator';

    /**
     * @var bool
     */
    protected bool $bootstrapEnabled = true;

    /**
     * @var bool
     */
    protected bool $consoleEnabled = true;

    /**
     * @var bool
     */
    protected bool $middlewareEnabled = false;

    /**
     * @var bool
     */
    protected bool $routesEnabled = false;

    /**
     * Load Explorator configuration.
     *
     * @param \Cake\Core\PluginApplicationInterface $app Application instance
     * @return void
     */
    #[Override]
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        if (!Configure::check('Explorator')) {
            if (file_exists(CONFIG . 'explorator.php')) {
                Configure::load('explorator', 'default');
            } elseif (file_exists($this->getConfigPath() . 'explorator.php')) {
                Configure::load('Crustum/Explorator.explorator', 'default', false);
            }
        }
    }

    /**
     * Register Explorator engine manager and local engines.
     *
     * @param \Cake\Core\ContainerInterface $container Application container
     * @return void
     */
    public function services(ContainerInterface $container): void
    {
        $container->addShared(EngineManager::class, fn(): EngineManager => new EngineManager());
        $container->addShared(
            SearchableIndexer::class,
            fn(): SearchableIndexer => new SearchableIndexer($container->get(EngineManager::class)),
        );
        $container->addShared(NullEngine::class, fn(): NullEngine => new NullEngine());
        $container->addShared(
            CollectionEngine::class,
            fn(): CollectionEngine => new CollectionEngine(),
        );
        $container->addShared(
            DatabaseEngine::class,
            fn(): DatabaseEngine => new DatabaseEngine(),
        );
    }

    /**
     * No HTTP middleware in Explorator v1 scaffold.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue Middleware queue
     * @return \Cake\Http\MiddlewareQueue
     */
    #[Override]
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue;
    }

    /**
     * Get the manifest for the plugin.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__);

        return array_merge(
            static::manifestConfig(
                $pluginPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'explorator.php',
                CONFIG . 'explorator.php',
                false,
            ),
            static::manifestBootstrapAppend(
                "if (file_exists(CONFIG . 'explorator.php')) {\n    Configure::load('explorator', 'default');\n}",
                '// Explorator Plugin Configuration',
            ),
            static::manifestStarRepo('Crustum/explorator'),
        );
    }
}
