<?php
declare(strict_types=1);

namespace Crustum\Explorator\Job;

/**
 * Unique variant of RemoveFromSearchJob.
 *
 * Duplicate pushes (same class and payload) are ignored by the queue.
 * Requires `uniqueCache` in the queue configuration.
 */
class RemoveFromSearchUniquelyJob extends RemoveFromSearchJob
{
    /**
     * @var bool
     */
    public static bool $shouldBeUnique = true;
}
