<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Override;

/**
 * Asserts at least one delete was recorded for a table (optional key).
 *
 * @internal
 */
class RemovedFromSearch extends ExploratorConstraintBase
{
    /**
     * @param mixed $other Table alias string, or array{table: string, key?: mixed}
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        if (is_array($other)) {
            $table = (string)($other['table'] ?? '');
            $key = $other['key'] ?? null;
        } else {
            $table = (string)$other;
            $key = null;
        }

        foreach ($this->getOperations() as $operation) {
            if ($operation['operation'] !== 'delete') {
                continue;
            }

            if ($operation['table'] !== $table) {
                continue;
            }

            if ($key === null || $this->operationHasKey($operation, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'entities were removed from search';
    }
}
