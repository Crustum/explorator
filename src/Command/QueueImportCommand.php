<?php
declare(strict_types=1);

namespace Crustum\Explorator\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Crustum\Explorator\Explorator;
use Crustum\Explorator\Job\MakeRangeSearchableJob;
use Override;

/**
 * Queue range import jobs for a searchable table.
 */
class QueueImportCommand extends ExploratorCommand
{
    /**
     * @inheritDoc
     */
    #[Override]
    public static function defaultName(): string
    {
        return 'explorator queue-import';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Import the given table into the search index via chunked, queued jobs';
    }

    /**
     * @inheritDoc
     */
    #[Override]
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription(static::getDescription())
            ->addArgument('table', [
                'help' => 'Table locator alias',
                'required' => true,
            ])
            ->addOption('min', ['help' => 'Minimum explorator key'])
            ->addOption('max', ['help' => 'Maximum explorator key'])
            ->addOption('chunk', [
                'short' => 'c',
                'help' => 'Range size per job',
            ])
            ->addOption('order', [
                'help' => 'asc or desc',
                'default' => 'asc',
            ])
            ->addOption('queue', [
                'help' => 'Queue name for range jobs',
            ]);

        return $parser;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $alias = (string)$args->getArgument('table');
        $table = $this->resolveSearchableTable($alias);
        $keyName = $this->exploratorKeyName($table);

        $minOpt = $args->getOption('min');
        $maxOpt = $args->getOption('max');
        $min = is_numeric($minOpt)
            ? (int)$minOpt
            : (int)$table->find()->select([$keyName])->orderBy([$keyName => 'ASC'])->first()?->get($keyName);
        $max = is_numeric($maxOpt)
            ? (int)$maxOpt
            : (int)$table->find()->select([$keyName])->orderBy([$keyName => 'DESC'])->first()?->get($keyName);

        $chunkOpt = $args->getOption('chunk');
        $chunk = is_numeric($chunkOpt) && (int)$chunkOpt > 0
            ? (int)$chunkOpt
            : max(1, (int)Configure::read('Explorator.chunk.searchable', 500));
        $order = strtolower((string)($args->getOption('order') ?: 'asc'));

        if (!in_array($order, ['asc', 'desc'], true)) {
            $io->error('The order option must be either "asc" or "desc".');

            return static::CODE_ERROR;
        }

        if ($max < $min || (!is_numeric($minOpt) && !is_numeric($maxOpt) && ($min < 1 || $max < 1))) {
            $io->out(sprintf('No records found for [%s].', $alias));

            return static::CODE_SUCCESS;
        }

        $ranges = [];
        if ($order === 'asc') {
            for ($start = $min; $start <= $max; $start += $chunk) {
                $ranges[] = [$start, min($start + $chunk - 1, $max)];
            }
        } else {
            for ($end = $max; $end >= $min; $end -= $chunk) {
                $ranges[] = [max($end - $chunk + 1, $min), $end];
            }
        }

        $queueOption = $args->getOption('queue');
        $queue = is_string($queueOption) && $queueOption !== ''
            ? $queueOption
            : (method_exists($table, 'syncWithSearchUsingQueue') ? $table->syncWithSearchUsingQueue() : null);
        $connection = method_exists($table, 'syncWithSearchUsing') ? $table->syncWithSearchUsing() : null;

        $pushOptions = [];
        $messageArgs = [];
        if (is_string($queue) && $queue !== '') {
            $pushOptions['queue'] = $queue;
            $messageArgs['queue'] = $queue;
        }

        if (is_string($connection) && $connection !== '') {
            $pushOptions['config'] = $connection;
            $messageArgs['connection'] = $connection;
        }

        foreach ($ranges as [$start, $end]) {
            Explorator::push(MakeRangeSearchableJob::class, array_merge([
                'source' => $alias,
                'start' => $start,
                'end' => $end,
            ], $messageArgs), $pushOptions);
            $direction = $order === 'asc' ? 'up to' : 'down to';
            $io->out(sprintf('Queued [%s] models %s ID: %d', $alias, $direction, $order === 'asc' ? $end : $start));
        }

        $io->success(sprintf('%d records have been queued for [%s].', count($ranges), $alias));

        return static::CODE_SUCCESS;
    }
}
