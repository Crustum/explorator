<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Crustum\Explorator\Attribute\SearchUsingFullText;
use Crustum\Explorator\Attribute\SearchUsingPrefix;
use Override;

/**
 * Entity for prefix / full-text attribute columns (Database Feature).
 */
class AttributeSearchableUser extends SearchableUser
{
    /**
     * @return array<string, mixed>
     */
    #[SearchUsingPrefix(['email'])]
    #[SearchUsingFullText(['name'], ['language' => 'english'])]
    #[Override]
    public function toSearchableArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ], $this->exploratorMetadata());
    }
}
