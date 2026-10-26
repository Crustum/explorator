<?php
declare(strict_types=1);

namespace Crustum\Explorator\Job;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Explorator\Explorator;
use Interop\Queue\Processor;

/**
 * Queue job: make a primary-key range searchable, then push MakeSearchableJob.
 */
class MakeRangeSearchableJob implements JobInterface
{
    use LocatorAwareTrait;

    /**
     * @param \Cake\Queue\Job\Message $message Queue message
     * @return string|null
     */
    public function execute(Message $message): ?string
    {
        $source = (string)$message->getArgument('source', '');
        $start = (int)$message->getArgument('start', 0);
        $end = (int)$message->getArgument('end', 0);
        if ($source === '' || $end < $start) {
            return Processor::ACK;
        }

        $table = $this->getTableLocator()->get($source);
        $keyName = method_exists($table, 'getExploratorKeyName')
            ? $table->getExploratorKeyName()
            : (string)$table->getPrimaryKey();

        $entities = $table->find()
            ->where([
                "{$keyName} >=" => $start,
                "{$keyName} <=" => $end,
            ])
            ->all()
            ->filter(fn($entity): bool => !method_exists($entity, 'shouldBeSearchable') || $entity->shouldBeSearchable());

        $ids = [];
        foreach ($entities as $entity) {
            $ids[] = method_exists($entity, 'getExploratorKey') ? $entity->getExploratorKey() : $entity->get($keyName);
        }

        if ($ids === []) {
            return Processor::ACK;
        }

        $options = [];
        $queue = $message->getArgument('queue', '');
        if (!is_string($queue) || $queue === '') {
            $queue = method_exists($table, 'syncWithSearchUsingQueue') ? $table->syncWithSearchUsingQueue() : null;
        }

        if (is_string($queue) && $queue !== '') {
            $options['queue'] = $queue;
        }

        $connection = $message->getArgument('connection', '');
        if (!is_string($connection) || $connection === '') {
            $connection = method_exists($table, 'syncWithSearchUsing') ? $table->syncWithSearchUsing() : null;
        }

        if (is_string($connection) && $connection !== '') {
            $options['config'] = $connection;
        }

        Explorator::push(Explorator::$makeSearchableJob, [
            'source' => $source,
            'ids' => $ids,
        ], $options);

        return Processor::ACK;
    }
}
