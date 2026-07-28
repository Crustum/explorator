<?php
declare(strict_types=1);

namespace Crustum\Explorator\Attribute;

use Attribute;

/**
 * Marks columns searched with database full-text operators.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class SearchUsingFullText
{
    /**
     * @param list<string>|string $columns Full-text columns
     * @param array<string, mixed> $options Full-text options (language, mode, etc.)
     */
    public function __construct(
        public array|string $columns,
        public array $options = [],
    ) {
        if (is_string($columns)) {
            $this->columns = [$columns];
        }
    }
}
