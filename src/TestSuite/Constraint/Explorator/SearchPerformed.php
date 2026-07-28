<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Crustum\Explorator\TestSuite\TestEngine;
use Override;

/**
 * Asserts a search or paginate was recorded (optional query / table).
 *
 * @internal
 */
class SearchPerformed extends ExploratorConstraintBase
{
    /**
     * @param mixed $other null, query string, or array{table?: string, query?: string|null}
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        $table = null;
        $query = null;

        if (is_array($other)) {
            $table = isset($other['table']) ? (string)$other['table'] : null;
            $query = $other['query'] ?? null;
        } elseif (is_string($other)) {
            $query = $other;
        }

        foreach (TestEngine::getSearches() as $operation) {
            if ($table !== null && $operation['table'] !== $table) {
                continue;
            }

            if ($query !== null && $operation['query'] !== $query) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'search was performed';
    }
}
