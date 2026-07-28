<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\Explorator\EngineManager;
use Override;

/**
 * Delete a search index.
 */
class DeleteIndexCommand extends ExploratorCommand
{
    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'explorator delete-index';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Delete an index';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription(static::getDescription())
            ->addArgument('name', [
                'help' => 'The name of the index',
                'required' => true,
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $name = (string)$args->getArgument('name');
        (new EngineManager())->engine()->deleteIndex($name);
        $io->success(sprintf('Index [%s] deleted.', $name));

        return static::CODE_SUCCESS;
    }
}
