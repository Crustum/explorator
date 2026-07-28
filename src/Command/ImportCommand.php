<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Override;

/**
 * Import a searchable table into the search index.
 */
class ImportCommand extends ExploratorCommand
{
    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'explorator import';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Import the given table into the search index';
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
                'help' => 'Table locator alias (e.g. SearchableUsers)',
                'required' => true,
            ])
            ->addOption('fresh', [
                'boolean' => true,
                'default' => false,
                'help' => 'Flush the index before importing',
            ])
            ->addOption('chunk', [
                'short' => 'c',
                'help' => 'Chunk size',
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $alias = (string)$args->getArgument('table');
        $table = $this->resolveSearchableTable($alias);
        $chunk = $args->getOption('chunk');
        $chunkSize = is_numeric($chunk) ? (int)$chunk : null;

        if ($args->getOption('fresh')) {
            $this->flushSearchable($table, chunk: $chunkSize);
        }

        $this->importSearchable($table, chunk: $chunkSize);
        $io->success(sprintf('All [%s] records have been imported.', $alias));

        return static::CODE_SUCCESS;
    }
}
