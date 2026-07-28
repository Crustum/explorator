<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Override;

/**
 * Entity with reversed searchable name (CollectionEngine Feature).
 */
class SearchableUserWithCustomSearchableData extends SearchableUser
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function toSearchableArray(): array
    {
        return [
            'reversed_name' => strrev((string)$this->get('name')),
        ];
    }
}
