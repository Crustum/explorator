<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

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
    public function execute(): ?int
    {
        $name = (string)$this->args->getArgument('name');
        $options = [];
        $key = $this->args->getOption('key');
        if (is_string($key) && $key !== '') {
            $options['primaryKey'] = $key;
        }

        new EngineManager()->engine()->createIndex($name, $options);
        $this->io->success(sprintf('Index [%s] created.', $name));

        return static::CODE_SUCCESS;
    }
}
