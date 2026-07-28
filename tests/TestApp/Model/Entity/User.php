<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Cake\ORM\Entity;

/**
 * Base user entity (workbench User stand-in).
 */
class User extends Entity
{
    /**
     * @var list<string>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = [
        'password',
    ];
}
