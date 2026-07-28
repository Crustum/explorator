<?php
declare(strict_types=1);

namespace Crustum\Explorator\Trait;

/**
 * Build a stable unique id from explorator keys in a job payload.
 */
trait UniqueByExploratorKeysTrait
{
    /**
     * @param string $jobClass Job class
     * @param list<mixed> $keys Explorator keys
     * @return string
     */
    protected function uniqueIdFromExploratorKeys(string $jobClass, array $keys): string
    {
        $sorted = $keys;
        sort($sorted);

        return md5($jobClass . ':' . json_encode($sorted));
    }
}
