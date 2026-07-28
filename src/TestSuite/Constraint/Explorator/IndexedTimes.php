<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Override;

/**
 * Asserts update was recorded a specific number of times for a table.
 *
 * @internal
 */
class IndexedTimes extends ExploratorConstraintBase
{
    /**
     * @var int
     */
    protected int $times;

    /**
     * @param int $times Expected update count
     */
    public function __construct(int $times)
    {
        parent::__construct();
        $this->times = $times;
    }

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

        $count = 0;
        foreach ($this->getOperations() as $operation) {
            if ($operation['operation'] !== 'update') {
                continue;
            }

            if ($operation['table'] !== $table) {
                continue;
            }

            if ($key === null || $this->operationHasKey($operation, $key)) {
                $count++;
            }
        }

        return $count === $this->times;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return sprintf('entities were indexed %d times', $this->times);
    }
}
