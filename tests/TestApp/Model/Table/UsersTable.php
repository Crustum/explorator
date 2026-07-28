<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Table;
use TestApp\Model\Entity\User;

/**
 * Users table (workbench User base — non-searchable).
 */
class UsersTable extends Table
{
    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('searchable_users');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->setEntityClass(User::class);
    }
}
