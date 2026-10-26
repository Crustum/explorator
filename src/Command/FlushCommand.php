<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

use Cake\Console\ConsoleOptionParser;
use Override;

/**
 * Flush a searchable table from the search index.
 */
class FlushCommand extends ExploratorCommand
{
    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'explorator flush';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return "Flush all of the table's records from the index";
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription(static::getDescription())
            ->addArgument('table', [
                'help' => 'Table locator alias',
                'required' => true,
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function execute(): ?int
    {
        $alias = (string)$this->args->getArgument('table');
        $table = $this->resolveSearchableTable($alias);
        $this->flushSearchable($table);

        $this->io->success(sprintf('All [%s] records have been flushed.', $alias));

        return static::CODE_SUCCESS;
    }
}
