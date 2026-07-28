<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Cake\Collection\CollectionInterface;
use Override;

/**
 * Entity that loads a virtual value via makeSearchableUsing (CollectionEngine Feature).
 */
class SearchableUserWithUnloadedValue extends SearchableUser
{
    /**
     * @var string|null
     */
    protected ?string $unloadedValue = null;

    /**
     * @inheritDoc
     */
    #[Override]
    public function toSearchableArray(): array
    {
        return [
            'value' => $this->unloadedValue,
        ];
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function makeSearchableUsing(iterable $entities): CollectionInterface
    {
        return collection($entities)->each(function (SearchableUserWithUnloadedValue $entity): void {
            $entity->unloadedValue = 'loaded';
        });
    }
}
