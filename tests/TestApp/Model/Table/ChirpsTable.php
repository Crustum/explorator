<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Table;
use Crustum\Explorator\Model\Trait\SearchableTrait;
use Crustum\Explorator\Model\Trait\SoftDeleteTrait;
use TestApp\Model\Entity\Chirp;

/**
 * Chirps table with custom Explorator key (workbench Chirp stand-in).
 */
class ChirpsTable extends Table
{
    use SearchableTrait;
    use SoftDeleteTrait;

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('chirps');
        $this->setDisplayField('content');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Chirp::class);
        $this->addBehavior('Crustum/Explorator.SoftDelete');
        $this->hasMany('Bookmarks', [
            'className' => BookmarksTable::class,
            'foreignKey' => 'chirp_id',
        ]);
    }

    /**
     * @inheritDoc
     */
    public function searchableAs(): string
    {
        return 'chirps';
    }

    /**
     * @inheritDoc
     */
    public function getExploratorKeyName(): string
    {
        return 'explorator_id';
    }
}
