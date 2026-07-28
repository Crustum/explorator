<?php
declare(strict_types=1);

namespace Crustum\Explorator\Job;

use Cake\Collection\Collection;

/**
 * Collection of removable explorator entities keyed by explorator key for queue payloads.
 *
 * @extends \Cake\Collection\Collection<int, array{source: string, id: mixed}>
 */
class RemovableExploratorCollection extends Collection
{
    /**
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return self
     */
    public static function fromEntities(iterable $entities): self
    {
        $rows = [];
        foreach ($entities as $entity) {
            $source = (string)$entity->getSource();
            $id = method_exists($entity, 'getExploratorKey')
                ? $entity->getExploratorKey()
                : $entity->get('id');
            $rows[] = [
                'source' => $source,
                'id' => $id,
            ];
        }

        return new self($rows);
    }

    /**
     * @return list<mixed>
     */
    public function exploratorKeys(): array
    {
        return $this->extract('id')->toList();
    }
}
