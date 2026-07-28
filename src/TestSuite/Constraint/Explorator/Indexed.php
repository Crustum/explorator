<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Override;

/**
 * Asserts at least one update was recorded for a table (optional key).
 *
 * @internal
 */
class Indexed extends ExploratorConstraintBase
{
    /**
     * @param mixed $other Table alias string, or array{table: string, key?: mixed}
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        [$table, $key] = $this->normalize($other);

        foreach ($this->getOperations() as $operation) {
            if ($operation['operation'] !== 'update') {
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
        if ($this->at !== null) {
            return sprintf('operation #%d was an index update', $this->at);
        }

        return 'entities were indexed';
    }

    /**
     * @param mixed $other Matcher input
     * @return array{0: string, 1: mixed}
     */
    protected function normalize(mixed $other): array
    {
        if (is_array($other)) {
            return [(string)($other['table'] ?? ''), $other['key'] ?? null];
        }

        return [(string)$other, null];
    }
}
