<?php
declare(strict_types=1);

namespace Crustum\Explorator;

use Cake\Event\EventManager;
use Crustum\Explorator\Engines\Engine;
use Crustum\Explorator\Event\SearchPerformed;
use Throwable;

/**
 * Times a Explorator search callback and dispatches SearchPerformed.
 */
final class SearchInstrumentation
{
    /**
     * Run a search callback, measure duration, and fire SearchPerformed.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param \Crustum\Explorator\Engines\Engine $engine Engine instance
     * @param callable(): mixed $callback Search callback returning raw results
     * @param string $operation Operation name (`search` or `paginate`)
     * @param int|null $page Page number when paginating
     * @param int|null $perPage Page size when paginating
     * @return mixed
     */
    public static function run(
        Builder $builder,
        Engine $engine,
        callable $callback,
        string $operation = 'search',
        ?int $page = null,
        ?int $perPage = null,
    ): mixed {
        if (EventManager::instance()->listeners(SearchPerformed::NAME) === []) {
            return $callback();
        }

        $started = hrtime(true);
        $results = $callback();
        $durationMs = (hrtime(true) - $started) / 1e6;

        try {
            EventManager::instance()->dispatch(
                SearchPerformed::fromSearch(
                    $builder,
                    $engine,
                    $results,
                    $durationMs,
                    $operation,
                    $page,
                    $perPage,
                ),
            );
        } catch (Throwable) {
        }

        return $results;
    }
}
