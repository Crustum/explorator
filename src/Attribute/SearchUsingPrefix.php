<?php
declare(strict_types=1);

namespace Crustum\Explorator\Attribute;

use Attribute;

/**
 * Marks columns searched with a prefix LIKE pattern.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class SearchUsingPrefix
{
    /**
     * @param list<string>|string $columns Prefix search columns
     */
    public function __construct(
        public array|string $columns,
    ) {
        if (is_string($columns)) {
            $this->columns = [$columns];
        }
    }
}
