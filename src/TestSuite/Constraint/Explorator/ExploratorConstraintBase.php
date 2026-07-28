<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Crustum\Explorator\TestSuite\TestEngine;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Base class for Explorator TestSuite assertion constraints.
 *
 * @internal
 */
abstract class ExploratorConstraintBase extends Constraint
{
    /**
     * Operation index to check (0-based).
     *
     * @var int|null
     */
    protected ?int $at = null;

    /**
     * @param int|null $at Optional index of a specific captured operation
     */
    public function __construct(?int $at = null)
    {
        $this->at = $at;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getOperations(): array
    {
        $operations = TestEngine::getOperations();

        if ($this->at !== null) {
            if (!isset($operations[$this->at])) {
                return [];
            }

            return [$operations[$this->at]];
        }

        return $operations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getWrites(): array
    {
        $writes = TestEngine::getWrites();

        if ($this->at !== null) {
            if (!isset($writes[$this->at])) {
                return [];
            }

            return [$writes[$this->at]];
        }

        return $writes;
    }

    /**
     * @param array<string, mixed> $operation Captured operation
     * @param mixed $key Explorator key
     * @return bool
     */
    protected function operationHasKey(array $operation, mixed $key): bool
    {
        return in_array($key, $operation['keys'] ?? [], false);
    }
}
