<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Override;

/**
 * Versionable searchable table.
 *
 * searchableAs = search index; indexableAs = write index (Meili different-indexes test).
 */
class VersionableUsersTable extends SearchableUsersTable
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function searchableAs(): string
    {
        return 'table';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function indexableAs(): string
    {
        return 'table_v2';
    }
}
