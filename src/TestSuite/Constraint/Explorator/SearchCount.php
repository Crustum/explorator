<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Crustum\Explorator\TestSuite\TestEngine;
use Override;

/**
 * Asserts search/paginate count.
 *
 * @internal
 */
class SearchCount extends ExploratorConstraintBase
{
    /**
     * @param mixed $other Expected count
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        return count(TestEngine::getSearches()) === $other;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('search count is (actual: %d)', count(TestEngine::getSearches()));
    }
}
