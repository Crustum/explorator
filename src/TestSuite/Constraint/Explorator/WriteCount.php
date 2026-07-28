<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Override;

/**
 * Asserts write operation count (update + delete + flush).
 *
 * @internal
 */
class WriteCount extends ExploratorConstraintBase
{
    /**
     * @param mixed $other Expected count
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        return count($this->getWrites()) === $other;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('index write count is (actual: %d)', count($this->getWrites()));
    }
}
