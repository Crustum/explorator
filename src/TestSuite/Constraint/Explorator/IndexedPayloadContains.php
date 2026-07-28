<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite\Constraint\Explorator;

use Override;

/**
 * Asserts an update payload contains a key/value (loose ==).
 *
 * @internal
 */
class IndexedPayloadContains extends ExploratorConstraintBase
{
    /**
     * @param mixed $other array{table: string, key: string, value: mixed, exploratorKey?: mixed}
     * @return bool
     */
    #[Override]
    protected function matches(mixed $other): bool
    {
        $table = (string)($other['table'] ?? '');
        $payloadKey = (string)($other['key'] ?? '');
        $value = $other['value'] ?? null;
        $exploratorKey = $other['exploratorKey'] ?? null;

        foreach ($this->getOperations() as $operation) {
            if ($operation['operation'] !== 'update') {
                continue;
            }

            if ($operation['table'] !== $table) {
                continue;
            }

            if ($exploratorKey !== null && !$this->operationHasKey($operation, $exploratorKey)) {
                continue;
            }

            foreach ($operation['payloads'] ?? [] as $payload) {
                if (is_array($payload) && array_key_exists($payloadKey, $payload) && $payload[$payloadKey] == $value) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return 'indexed payload contains expected data';
    }
}
