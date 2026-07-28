<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

use Cake\Command\Command;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Crustum\Explorator\Exception\ExploratorException;

/**
 * Shared helpers for Explorator console commands.
 */
abstract class ExploratorCommand extends Command
{
    use LocatorAwareTrait;

    /**
     * Resolve a Table that uses SearchableTrait (handy API methods).
     *
     * @param string $alias Table locator alias (e.g. SearchableUsers)
     * @return \Cake\ORM\Table
     * @throws \Crustum\Explorator\Exception\ExploratorException
     */
    protected function resolveSearchableTable(string $alias): Table
    {
        if ($alias === '') {
            throw new ExploratorException('Table alias is required.');
        }

        $table = $this->getTableLocator()->get($alias);
        if (!$this->tableUsesSearchableTrait($table)) {
            throw new ExploratorException(sprintf(
                'Table [%s] must use Crustum\\Explorator\\Model\\Trait\\SearchableTrait.',
                $alias,
            ));
        }

        return $table;
    }

    /**
     * @param \Cake\ORM\Table $table Table
     * @param \Cake\ORM\Query\SelectQuery|null $query Query
     * @param int|null $chunk Chunk size
     * @return void
     */
    protected function importSearchable(Table $table, ?SelectQuery $query = null, ?int $chunk = null): void
    {
        if (!method_exists($table, 'importSearchable')) {
            throw new ExploratorException(sprintf(
                'Table [%s] must use SearchableTrait.',
                $table->getAlias(),
            ));
        }

        $table->importSearchable($query, $chunk);
    }

    /**
     * @param \Cake\ORM\Table $table Table
     * @param \Cake\ORM\Query\SelectQuery|null $query Query
     * @param int|null $chunk Chunk size
     * @return void
     */
    protected function flushSearchable(Table $table, ?SelectQuery $query = null, ?int $chunk = null): void
    {
        if (!method_exists($table, 'flushSearchable')) {
            throw new ExploratorException(sprintf(
                'Table [%s] must use SearchableTrait.',
                $table->getAlias(),
            ));
        }

        $table->flushSearchable($query, $chunk);
    }

    /**
     * @param \Cake\ORM\Table $table Table
     * @return string
     */
    protected function exploratorKeyName(Table $table): string
    {
        if (!method_exists($table, 'getExploratorKeyName')) {
            throw new ExploratorException(sprintf(
                'Table [%s] must use SearchableTrait.',
                $table->getAlias(),
            ));
        }

        return $table->getExploratorKeyName();
    }

    /**
     * @param \Cake\ORM\Table $table Table
     * @return bool
     */
    protected function tableUsesSearchableTrait(Table $table): bool
    {
        return method_exists($table, 'importSearchable')
            && method_exists($table, 'flushSearchable')
            && method_exists($table, 'getExploratorKeyName');
    }
}
