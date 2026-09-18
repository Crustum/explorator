<?php
declare(strict_types=1);

namespace Crustum\Explorator;

use Cake\Core\Configure;
use Cake\Queue\QueueManager;
use Crustum\Explorator\Job\MakeSearchableJob;
use Crustum\Explorator\Job\RemoveFromSearchJob;

/**
 * Explorator package helpers and version.
 */
class Explorator
{
    /**
     * The Explorator library version.
     *
     * @var string
     */
    public const VERSION = '0.1.0-dev';

    /**
     * Job class used when queueing make-searchable work.
     *
     * @var class-string<\Cake\Queue\Job\JobInterface>
     */
    public static string $makeSearchableJob = MakeSearchableJob::class;

    /**
     * Job class used when queueing remove-from-search work.
     *
     * @var class-string<\Cake\Queue\Job\JobInterface>
     */
    public static string $removeFromSearchJob = RemoveFromSearchJob::class;

    /**
     * @param class-string<\Cake\Queue\Job\JobInterface> $class Job class
     * @return void
     */
    public static function makeSearchableUsing(string $class): void
    {
        static::$makeSearchableJob = $class;
    }

    /**
     * @param class-string<\Cake\Queue\Job\JobInterface> $class Job class
     * @return void
     */
    public static function removeFromSearchUsing(string $class): void
    {
        static::$removeFromSearchJob = $class;
    }

    /**
     * Push a job onto the CakePHP queue.
     *
     * @param array{0: class-string, 1: string}|class-string<\Cake\Queue\Job\JobInterface> $class Job class
     * @param array<string, mixed> $data Job payload
     * @param array<string, mixed> $options Queue options
     * @return void
     */
    public static function push(string|array $class, array $data = [], array $options = []): void
    {
        if (isset($data['ids']) && is_array($data['ids'])) {
            // Normalize key order so unique jobs deduplicate identical id sets.
            $ids = array_values($data['ids']);
            sort($ids);
            $data['ids'] = $ids;
        }

        $options = array_merge(static::defaultJobOptions(), $options);

        QueueManager::push($class, $data, $options);
    }

    /**
     * Global push options for Explorator jobs from `Explorator.jobs.options`.
     *
     * Only keys supported by QueueManager::push() are passed through.
     * Job retries are not push options: control them with `$maxAttempts`
     * on custom job classes or the worker `--max-attempts` option.
     *
     * @return array{delay?: int, expires?: int, priority?: int|string}
     */
    protected static function defaultJobOptions(): array
    {
        $configured = Configure::read('Explorator.jobs.options');
        if (!is_array($configured)) {
            return [];
        }

        $options = [];
        foreach (['delay', 'expires'] as $key) {
            if (isset($configured[$key]) && is_numeric($configured[$key])) {
                $options[$key] = (int)$configured[$key];
            }
        }

        if (isset($configured['priority']) && (is_string($configured['priority']) || is_int($configured['priority']))) {
            $options['priority'] = $configured['priority'];
        }

        return $options;
    }
}
