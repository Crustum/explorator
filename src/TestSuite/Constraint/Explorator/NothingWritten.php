<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Override;

/**
 * Asserts no update/delete/flush operations were recorded.
 *
 * @internal
 */
class NothingWritten extends ExploratorConstraintBase
{
    /**
     * @param mixed $other Unused
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        return $this->getWrites() === [];
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('no index writes were performed (actual: %d)', count($this->getWrites()));
    }
}
