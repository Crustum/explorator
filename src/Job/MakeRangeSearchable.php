<?php
declare(strict_types=1);

namespace Crustum\Explorator\Job;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Explorator\Explorator;
use Crustum\Explorator\Trait\ConfiguresJobOptionsTrait;
use Interop\Queue\Processor;

/**
 * Queue job: make a primary-key range searchable, then push MakeSearchable.
 */
class MakeRangeSearchable implements JobInterface
{
    use ConfiguresJobOptionsTrait;
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

        Explorator::push(Explorator::$makeSearchableJob, [
            'source' => $source,
            'ids' => $ids,
        ]);

        return Processor::ACK;
    }
}
