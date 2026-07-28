<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Crustum\Explorator\Model\Trait\SearchableTrait;
use Crustum\Explorator\Model\Trait\SoftDeleteTrait;
use Override;
use TestApp\Model\Entity\SearchableUser;

/**
 * Searchable users table (workbench SearchableUser stand-in).
 */
class SearchableUsersTable extends UsersTable
{
    use SearchableTrait;
    use SoftDeleteTrait;

    /**
     * @inheritDoc
     */
    #[Override]
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setEntityClass(SearchableUser::class);
        $this->addBehavior('Crustum/Explorator.SoftDelete');
    }
}
