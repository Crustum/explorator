<?php
declare(strict_types=1);

namespace Crustum\Explorator;

use Cake\Queue\QueueManager;
use Crustum\Explorator\Job\MakeSearchable;
use Crustum\Explorator\Job\RemoveFromSearch;

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
    public static string $makeSearchableJob = MakeSearchable::class;

    /**
     * Job class used when queueing remove-from-search work.
     *
     * @var class-string<\Cake\Queue\Job\JobInterface>
     */
    public static string $removeFromSearchJob = RemoveFromSearch::class;

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
        QueueManager::push($class, $data, $options);
    }
}
