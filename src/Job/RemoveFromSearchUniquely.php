<?php
declare(strict_types=1);

namespace Crustum\Explorator\Job;

use Crustum\Explorator\Trait\UniqueByExploratorKeysTrait;

/**
 * Unique variant of RemoveFromSearch.
 */
class RemoveFromSearchUniquely extends RemoveFromSearch
{
    use UniqueByExploratorKeysTrait;

    /**
     * @param string $source Table alias
     * @param list<mixed> $ids Explorator keys
     * @return string
     */
    public function uniqueId(string $source, array $ids): string
    {
        return $this->uniqueIdFromExploratorKeys(static::class . ':' . $source, $ids);
    }
}
