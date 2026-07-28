<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Crustum\Explorator\TestSuite\TestEngine;
use Override;

/**
 * Asserts no search/paginate operations were recorded.
 *
 * @internal
 */
class NoSearchPerformed extends ExploratorConstraintBase
{
    /**
     * @param mixed $other Unused
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        return TestEngine::getSearches() === [];
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('no searches were performed (actual: %d)', count(TestEngine::getSearches()));
    }
}
