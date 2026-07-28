<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Crustum\Explorator\EngineManager;
use Override;
use Throwable;

/**
 * Delete all configured search indexes (best-effort for remote engines).
 */
class DeleteAllIndexesCommand extends ExploratorCommand
{
    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'explorator delete-all-indexes';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Delete all indexes';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser->setDescription(static::getDescription());
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        unset($args);
        $engine = (new EngineManager())->engine();

        if (!method_exists($engine, 'deleteAllIndexes')) {
            $io->error('The [' . Configure::read('Explorator.driver') . '] engine does not support deleting all indexes.');

            return static::CODE_ERROR;
        }

        try {
            $engine->deleteAllIndexes();
            $io->success('All indexes deleted successfully.');
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }
}
