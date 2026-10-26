<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Cake\ORM\Entity;
use Crustum\Explorator\Model\Trait\SearchableEntityTrait;
use Override;

/**
 * Bookmark entity joined to chirps for database engine search (workbench Bookmark).
 */
class Bookmark extends Entity
{
    use SearchableEntityTrait;

    /**
     * @var array<string, bool>
     */
    #[Override]
    protected array $patchable = [
        '*' => true,
        'id' => false,
    ];

    /**
     * @inheritDoc
     */
    public function toSearchableArray(): array
    {
        if (isset($_ENV['bookmark.toSearchableArray'])) {
            $value = $_ENV['bookmark.toSearchableArray'];

            return is_callable($value) ? $value($this) : (array)$value;
        }

        return [
            'label' => $this->label,
            'Chirps.content' => (string)($this->chirp->content ?? ''),
        ];
    }
}
