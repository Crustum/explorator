<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Crustum\Explorator\Model\Trait\SearchableEntityTrait;

/**
 * Searchable user entity (workbench SearchableUser stand-in).
 */
class SearchableUser extends User
{
    use SearchableEntityTrait;

    /**
     * @inheritDoc
     */
    public function toSearchableArray(): array
    {
        if (isset($_ENV['user.toSearchableArray'])) {
            $value = $_ENV['user.toSearchableArray'];

            return is_callable($value) ? $value($this) : (array)$value;
        }

        return array_merge([
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'age' => $this->age,
        ], $this->exploratorMetadata());
    }

    /**
     * @inheritDoc
     */
    public function wasSearchableBeforeUpdate(): bool
    {
        if (isset($_ENV['user.wasSearchableBeforeUpdate'])) {
            $value = $_ENV['user.wasSearchableBeforeUpdate'];

            return is_callable($value) ? (bool)$value($this) : (bool)$value;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function wasSearchableBeforeDelete(): bool
    {
        if (isset($_ENV['user.wasSearchableBeforeDelete'])) {
            $value = $_ENV['user.wasSearchableBeforeDelete'];

            return is_callable($value) ? (bool)$value($this) : (bool)$value;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function shouldBeSearchable(): bool
    {
        if (isset($_ENV['user.shouldBeSearchable'])) {
            $value = $_ENV['user.shouldBeSearchable'];

            return is_callable($value) ? (bool)$value($this) : (bool)$value;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        if (isset($_ENV['user.searchIndexShouldBeUpdated'])) {
            $value = $_ENV['user.searchIndexShouldBeUpdated'];

            return is_callable($value) ? (bool)$value($this) : (bool)$value;
        }

        return true;
    }
}
