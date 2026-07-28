<?php
declare(strict_types=1);

namespace Crustum\Explorator\Event;

use Cake\Event\Event;

/**
 * Fired after a chunk of entities was removed from the search index.
 *
 * @extends \Cake\Event\Event<\Cake\ORM\Table>
 */
class ModelsFlushed extends Event
{
    public const NAME = 'Explorator.ModelsFlushed';

    /**
     * @param \Cake\ORM\Table $subject Table
     * @param list<\Cake\Datasource\EntityInterface> $entities Flushed entities
     */
    public function __construct(object $subject, array $entities)
    {
        parent::__construct(self::NAME, $subject, ['entities' => $entities]);
    }

    /**
     * @return list<\Cake\Datasource\EntityInterface>
     */
    public function getEntities(): array
    {
        /** @var list<\Cake\Datasource\EntityInterface> $entities */
        $entities = $this->getData('entities') ?? [];

        return $entities;
    }
}
