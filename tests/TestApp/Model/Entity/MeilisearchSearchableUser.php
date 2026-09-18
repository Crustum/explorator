<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Override;

/**
 * Searchable user for Meilisearch/Algolia engine Feature tests (index payload hooks).
 */
class MeilisearchSearchableUser extends SearchableUser
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function toSearchableArray(): array
    {
        if (isset($_ENV['user.toSearchableArray'])) {
            $value = $_ENV['user.toSearchableArray'];

            return is_callable($value) ? $value($this) : (array)$value;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    /**
     * Searchable embedding source for semantic/hybrid tests.
     *
     * @return string|array<int, float>
     */
    public function toSearchableEmbedding(): string|array
    {
        if (isset($this->embedding) && is_array($this->embedding)) {
            return $this->embedding;
        }

        return (string)$this->name;
    }
}
