<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Override;

/**
 * Asserts flush was recorded for a table.
 *
 * @internal
 */
class Flushed extends ExploratorConstraintBase
{
    /**
     * @param mixed $other Table alias
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        $table = (string)$other;

        return array_any($this->getOperations(), fn(array $operation): bool => $operation['operation'] === 'flush' && $operation['table'] === $table);
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'search index was flushed';
    }
}
