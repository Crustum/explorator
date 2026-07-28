<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Crustum\Explorator\Contract\UpdatesIndexSettings;
use Crustum\Explorator\EngineManager;
use Override;
use Throwable;

/**
 * Sync index settings when the engine supports it.
 */
class SyncIndexSettingsCommand extends ExploratorCommand
{
    use LocatorAwareTrait;

    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'explorator sync-index-settings';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Sync your configured index settings with your search engine';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addOption('driver', [
                'help' => 'The search engine driver (defaults to Explorator.driver)',
                'default' => null,
            ]);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $driver = (string)($args->getOption('driver') ?: Configure::read('Explorator.driver', 'null'));
        $engine = (new EngineManager())->engine($driver);

        if (!$engine instanceof UpdatesIndexSettings) {
            $io->error(sprintf('The "%s" engine does not support updating index settings.', $driver));

            return static::CODE_ERROR;
        }

        try {
            $indexes = (array)Configure::read('Explorator.' . $driver . '.index-settings', []);
            if ($indexes === []) {
                $io->info(sprintf('No index settings found for the "%s" engine.', $driver));

                return static::CODE_SUCCESS;
            }

            foreach ($indexes as $name => $settings) {
                if (!is_array($settings)) {
                    $name = $settings;
                    $settings = [];
                }

                $table = null;
                if (is_string($name) && class_exists($name)) {
                    $table = new $name();
                } elseif (is_string($name) && $this->getTableLocator()->exists($name)) {
                    $table = $this->getTableLocator()->get($name);
                }

                if (
                    $table !== null
                    && (bool)Configure::read('Explorator.soft_delete', false)
                    && method_exists($table, 'hasBehavior')
                    && $table->hasBehavior('SoftDelete')
                ) {
                    $settings = $engine->configureSoftDeleteFilter($settings);
                }

                $indexName = $this->indexName(is_string($name) ? $name : (string)$name, $table);
                $engine->updateIndexSettings($indexName, $settings);
                $io->success(sprintf('Settings for the [%s] index synced successfully.', $indexName));
            }
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }

    /**
     * @param string $name Config key or class
     * @param object|null $table Optional table/model instance
     * @return string
     */
    protected function indexName(string $name, ?object $table): string
    {
        if ($table !== null) {
            if (method_exists($table, 'indexableAs')) {
                return $table->indexableAs();
            }

            if (method_exists($table, 'searchableAs')) {
                return $table->searchableAs();
            }
        }

        $prefix = (string)Configure::read('Explorator.prefix', '');
        if ($prefix !== '' && !str_starts_with($name, $prefix)) {
            return $prefix . $name;
        }

        return $name;
    }
}
