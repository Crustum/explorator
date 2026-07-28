<?php
declare(strict_types=1);

namespace Crustum\Explorator\Model\Trait;

use Cake\Collection\CollectionInterface;

/**
 * Entity-side Explorator hooks used by engines when indexing/searching.
 *
 * @mixin \Cake\ORM\Entity
 */
trait SearchableEntityTrait
{
    /**
     * Explorator metadata merged into searchable payloads.
     *
     * @var array<string, mixed>
     */
    protected array $exploratorMetadata = [];

    /**
     * Whether this entity should be included in search results / indexes.
     *
     * @return bool
     */
    public function shouldBeSearchable(): bool
    {
        return true;
    }

    /**
     * Sync soft-delete status into Explorator metadata (`__soft_deleted`).
     *
     * @param string $field Soft-delete column name
     * @return $this
     */
    public function pushSoftDeleteMetadata(string $field = 'deleted')
    {
        return $this->withExploratorMetadata('__soft_deleted', $this->get($field) !== null ? 1 : 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function exploratorMetadata(): array
    {
        return $this->exploratorMetadata;
    }

    /**
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return $this
     */
    public function withExploratorMetadata(string $key, mixed $value)
    {
        $this->exploratorMetadata[$key] = $value;

        return $this;
    }

    /**
     * Whether the entity is soft-deleted (`deleted` is set).
     *
     * @param string $field Soft-delete column name
     * @return bool
     */
    public function trashed(string $field = 'deleted'): bool
    {
        return $this->get($field) !== null;
    }

    /**
     * Whether the search index should be updated after this save.
     *
     * @return bool
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        return true;
    }

    /**
     * Whether the entity was searchable before the current update.
     *
     * @return bool
     */
    public function wasSearchableBeforeUpdate(): bool
    {
        return true;
    }

    /**
     * Whether the entity was searchable before delete.
     *
     * @return bool
     */
    public function wasSearchableBeforeDelete(): bool
    {
        return true;
    }

    /**
     * Indexable payload for engines.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return array_merge($this->toArray(), $this->exploratorMetadata());
    }

    /**
     * Explorator document key value.
     *
     * @return mixed
     */
    public function getExploratorKey(): mixed
    {
        return $this->get($this->getExploratorKeyName());
    }

    /**
     * Explorator document key column.
     *
     * @return string
     */
    public function getExploratorKeyName(): string
    {
        return 'id';
    }

    /**
     * Transform the entity collection before searchable filtering / indexing.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    public function makeSearchableUsing(iterable $entities): CollectionInterface
    {
        return collection($entities);
    }
}
