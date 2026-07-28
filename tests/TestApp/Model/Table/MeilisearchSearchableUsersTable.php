<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Override;
use TestApp\Model\Entity\MeilisearchSearchableUser;

/**
 * Searchable users table for Meilisearch engine Feature tests (index name `users`).
 */
class MeilisearchSearchableUsersTable extends SearchableUsersTable
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setEntityClass(MeilisearchSearchableUser::class);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function searchableAs(): string
    {
        return 'users';
    }
}
