<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Cake\ORM\Entity;
use Crustum\Explorator\Model\Trait\SearchableEntityTrait;

/**
 * Chirp entity with custom Explorator key (workbench Chirp stand-in).
 */
class Chirp extends Entity
{
    use SearchableEntityTrait;

    /**
     * @var list<string>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * @inheritDoc
     */
    public function getExploratorKey(): mixed
    {
        return $this->explorator_id;
    }

    /**
     * @inheritDoc
     */
    public function getExploratorKeyName(): string
    {
        return 'explorator_id';
    }

    /**
     * @inheritDoc
     */
    public function toSearchableArray(): array
    {
        if (isset($_ENV['chirp.toSearchableArray'])) {
            $value = $_ENV['chirp.toSearchableArray'];

            return is_callable($value) ? $value($this) : (array)$value;
        }

        return [
            'content' => $this->content,
        ];
    }
}
