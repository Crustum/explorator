<?php
declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Model\Trait\SearchableTrait;
use TestApp\Model\Entity\Bookmark;

/**
 * Bookmarks table with custom Explorator query joining chirps (workbench Bookmark).
 */
class BookmarksTable extends Table
{
    use SearchableTrait;

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('bookmarks');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');
        $this->setEntityClass(Bookmark::class);
        $this->belongsTo('Chirps', [
            'className' => ChirpsTable::class,
            'foreignKey' => 'chirp_id',
        ]);
    }

    /**
     * Database-engine base query with chirps join.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function newExploratorQuery(Builder $builder): SelectQuery
    {
        unset($builder);

        return $this->find()
            ->contain(['Chirps'])
            ->innerJoinWith('Chirps');
    }
}
