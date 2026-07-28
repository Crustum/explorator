<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\Explorator\EngineManager;
use Override;

/**
 * Create a search index.
 */
class IndexCommand extends ExploratorCommand
{
    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'explorator index';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Create an index';
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
            ])
            ->addOption('key', [
                'short' => 'k',
                'help' => 'Primary key name',
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
        $options = [];
        $key = $args->getOption('key');
        if (is_string($key) && $key !== '') {
            $options['primaryKey'] = $key;
        }

        (new EngineManager())->engine()->createIndex($name, $options);
        $io->success(sprintf('Index [%s] created.', $name));

        return static::CODE_SUCCESS;
    }
}
