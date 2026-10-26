<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

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
    public function execute(): ?int
    {
        $alias = (string)$this->args->getArgument('table');
        $table = $this->resolveSearchableTable($alias);
        $chunk = $this->args->getOption('chunk');
        $chunkSize = is_numeric($chunk) ? (int)$chunk : null;

        if ($this->args->getOption('fresh')) {
            $this->flushSearchable($table, chunk: $chunkSize);
        }

        $this->importSearchable($table, chunk: $chunkSize);
        $this->io->success(sprintf('All [%s] records have been imported.', $alias));

        return static::CODE_SUCCESS;
    }
}
