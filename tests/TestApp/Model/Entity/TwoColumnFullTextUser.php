<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Crustum\Explorator\Attribute\SearchUsingFullText;
use Override;

/**
 * User variant with two full-text text columns, one nullable.
 *
 * Postgres full-text concatenates `to_tsvector(lang, col)` with `||`; a NULL
 * column makes the whole expression NULL and `NULL @@ tsquery` never matches.
 * With `bio` nullable, a row where bio is NULL must still be found via `name`.
 */
class TwoColumnFullTextUser extends SearchableUser
{
    /**
     * @return array<string, mixed>
     */
    #[SearchUsingFullText(['name', 'bio'], ['language' => 'english'])]
    #[Override]
    public function toSearchableArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'name' => $this->name,
            'bio' => $this->bio,
        ], $this->exploratorMetadata());
    }
}
