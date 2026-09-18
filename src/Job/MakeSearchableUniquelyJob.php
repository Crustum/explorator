<?php
declare(strict_types=1);

namespace Crustum\Explorator\Job;

/**
 * Unique variant of MakeSearchableJob.
 *
 * Duplicate pushes (same class and payload) are ignored by the queue.
 * Requires `uniqueCache` in the queue configuration.
 */
class MakeSearchableUniquelyJob extends MakeSearchableJob
{
    /**
     * @var bool
     */
    public static bool $shouldBeUnique = true;
}
