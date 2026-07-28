<?php
declare(strict_types=1);

namespace Crustum\Explorator;

use Cake\Event\EventManager;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Event\IndexWritePerformed;
use Throwable;

/**
 * Times a Explorator index write callback and dispatches IndexWritePerformed.
 */
final class IndexWriteInstrumentation
{
    /**
     * Run an index write callback, measure duration, and fire IndexWritePerformed.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Entities being written
     * @param \Crustum\Explorator\Engines\Engine $engine Engine instance
     * @param callable(): void $callback Write callback
     * @param string $operation Operation name (`update` or `delete`)
     * @return void
     */
    public static function run(
        iterable $entities,
        Engine $engine,
        callable $callback,
        string $operation,
    ): void {
        if (EventManager::instance()->listeners(IndexWritePerformed::NAME) === []) {
            $callback();

            return;
        }

        $started = hrtime(true);
        $callback();
        $durationMs = (hrtime(true) - $started) / 1e6;

        try {
            EventManager::instance()->dispatch(
                IndexWritePerformed::fromWrite($entities, $engine, $durationMs, $operation),
            );
        } catch (Throwable) {
        }
    }
}
