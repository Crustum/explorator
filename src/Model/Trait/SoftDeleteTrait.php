<?php
declare(strict_types=1);

namespace Crustum\Explorator\Model\Trait;

use Cake\Datasource\EntityInterface;
use Crustum\Explorator\Model\Behavior\SoftDeleteBehavior;
use RuntimeException;

/**
 * Table-side soft-delete helpers (field `deleted`).
 *
 * @mixin \Cake\ORM\Table
 */
trait SoftDeleteTrait
{
    /**
     * Restore a soft-deleted entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function restore(EntityInterface $entity): EntityInterface|false
    {
        return $this->softDeleteBehavior()->restore($entity);
    }

    /**
     * Permanently delete an entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return bool
     */
    public function forceDelete(EntityInterface $entity): bool
    {
        return $this->softDeleteBehavior()->forceDelete($entity);
    }

    /**
     * @return \Crustum\Explorator\Model\Behavior\SoftDeleteBehavior
     */
    protected function softDeleteBehavior(): SoftDeleteBehavior
    {
        $behavior = $this->getBehavior('SoftDelete');
        if (!$behavior instanceof SoftDeleteBehavior) {
            throw new RuntimeException('SoftDelete behavior is not registered on this table.');
        }

        return $behavior;
    }
}
