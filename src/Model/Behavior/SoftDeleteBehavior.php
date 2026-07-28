<?php
declare(strict_types=1);

namespace Crustum\Explorator\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Behavior;
use Cake\ORM\Query\SelectQuery;
use RuntimeException;

/**
 * Soft-delete rows via a nullable datetime `deleted` column (not `deleted_at`).
 */
class SoftDeleteBehavior extends Behavior
{
    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'field' => 'deleted',
        'implementedFinders' => [
            'withTrashed' => 'findWithTrashed',
            'onlyTrashed' => 'findOnlyTrashed',
        ],
        'implementedMethods' => [],
    ];

    /**
     * Exclude soft-deleted rows unless withTrashed / onlyTrashed.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event Event
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @param \ArrayObject<string, mixed> $options Options
     * @param bool $primary Whether this is the primary table
     * @return void
     */
    public function beforeFind(
        EventInterface $event,
        SelectQuery $query,
        ArrayObject $options,
        bool $primary,
    ): void {
        unset($event, $primary);

        $field = $this->table()->aliasField($this->getConfig('field'));

        if (!empty($options['onlyTrashed'])) {
            $query->where(static fn($exp) => $exp->isNotNull($field));

            return;
        }

        if (!empty($options['withTrashed'])) {
            return;
        }

        $query->where(static fn($exp) => $exp->isNull($field));
    }

    /**
     * Convert delete into soft-delete unless `force` is set.
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event Event
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject<string, mixed> $options Options
     * @return void
     */
    public function beforeDelete(
        EventInterface $event,
        EntityInterface $entity,
        ArrayObject $options,
    ): void {
        if (!empty($options['force'])) {
            $entity->set('_forceDeleting', true);

            return;
        }

        $field = (string)$this->getConfig('field');
        $now = new DateTime();
        $table = $this->table();
        $primaryKey = $table->getPrimaryKey();
        $key = is_array($primaryKey) ? $primaryKey[0] : $primaryKey;
        $keyValue = $entity->get($key);

        $affected = $table->updateAll(
            [$field => $now],
            [$key => $keyValue],
        );

        if ($affected === 0) {
            throw new RuntimeException(sprintf(
                'Soft delete updateAll affected 0 rows for %s=%s',
                $key,
                var_export($keyValue, true),
            ));
        }

        $entity->set($field, $now);
        $entity->setDirty($field, false);
        $options['_softDeleted'] = true;

        $table->dispatchEvent('Model.afterDelete', [
            'entity' => $entity,
            'options' => $options,
        ]);

        $event->stopPropagation();
        $event->setResult(true);
    }

    /**
     * Finder that includes soft-deleted rows.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @param array<string, mixed> $options Options
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithTrashed(SelectQuery $query, array $options = []): SelectQuery
    {
        unset($options);

        return $query->applyOptions(['withTrashed' => true]);
    }

    /**
     * Finder that returns only soft-deleted rows.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query
     * @param array<string, mixed> $options Options
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findOnlyTrashed(SelectQuery $query, array $options = []): SelectQuery
    {
        unset($options);

        return $query->applyOptions(['onlyTrashed' => true]);
    }

    /**
     * Restore a soft-deleted entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function restore(EntityInterface $entity): EntityInterface|false
    {
        $field = (string)$this->getConfig('field');
        $entity->set($field);
        $result = $this->table()->save($entity);

        if ($result) {
            $this->table()->dispatchEvent('Model.afterRestore', [
                'entity' => $entity,
                'options' => new ArrayObject(),
            ]);
        }

        return $result;
    }

    /**
     * Permanently delete an entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return bool
     */
    public function forceDelete(EntityInterface $entity): bool
    {
        return $this->table()->delete($entity, ['force' => true]);
    }

    /**
     * Whether the current delete is a force delete.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return bool
     */
    public function isForceDeleting(EntityInterface $entity): bool
    {
        return (bool)$entity->get('_forceDeleting');
    }

    /**
     * Whether the entity is soft-deleted.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return bool
     */
    public function isTrashed(EntityInterface $entity): bool
    {
        return $entity->get((string)$this->getConfig('field')) !== null;
    }
}
