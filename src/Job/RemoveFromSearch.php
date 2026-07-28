<?php
declare(strict_types=1);

namespace Crustum\Explorator\Job;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Queue\Job\JobInterface;
use Cake\Queue\Job\Message;
use Crustum\Explorator\EngineManager;
use Crustum\Explorator\SearchableIndexer;
use Crustum\Explorator\Trait\ConfiguresJobOptionsTrait;
use Interop\Queue\Processor;

/**
 * Queue job: remove entities from search by table alias + ids.
 */
class RemoveFromSearch implements JobInterface
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
        $ids = $message->getArgument('ids', []);
        if ($source === '' || !is_array($ids) || $ids === []) {
            return Processor::ACK;
        }

        $table = $this->getTableLocator()->get($source);
        $keyName = method_exists($table, 'getExploratorKeyName')
            ? $table->getExploratorKeyName()
            : (string)$table->getPrimaryKey();
        $entities = $table->find()->whereInList($keyName, $ids)->all();

        $indexer = new SearchableIndexer(new EngineManager());
        $indexer->removeFromSearchSync($entities);

        return Processor::ACK;
    }
}
