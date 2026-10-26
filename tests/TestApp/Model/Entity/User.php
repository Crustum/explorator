<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Cake\ORM\Entity;
use Override;

/**
 * Base user entity (workbench User stand-in).
 */
class User extends Entity
{
    /**
     * @var array<string, bool>
     */
    #[Override]
    protected array $patchable = [
        '*' => true,
        'id' => false,
    ];

    /**
     * @var array<string>
     */
    #[Override]
    protected array $hidden = [
        'password',
    ];
}
