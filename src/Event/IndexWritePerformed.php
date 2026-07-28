<?php
declare(strict_types=1);

namespace Crustum\Explorator\Event;

use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Crustum\Explorator\Engines\Engine;
use Throwable;

/**
 * Fired after a Explorator index write (update or delete) completes.
 *
 * @extends \Cake\Event\Event<object>
 */
class IndexWritePerformed extends Event
{
    public const NAME = 'Explorator.IndexWritePerformed';

    /**
     * @param \Cake\ORM\Table|null $subject Table that was written
     * @param array{
     *     operation: string,
     *     count: int,
     *     index: string|null,
     *     engine: string,
     *     duration_ms: float,
     *     request: array{source: string, ids: list<mixed>},
     *     response: array{operation: string, count: int, index: string|null}
     * } $data Event payload
     */
    public function __construct(?Table $subject, array $data)
    {
        parent::__construct(self::NAME, $subject, $data);
    }

    /**
     * Build an IndexWritePerformed event from entities + engine result timing.
     *
     * @param iterable<\Cake\Datasource\EntityInterface> $entities Written entities
     * @param \Crustum\Explorator\Engines\Engine $engine Engine that ran the write
     * @param float $durationMs Duration in milliseconds
     * @param string $operation Operation name (`update` or `delete`)
     * @return self
     */
    public static function fromWrite(
        iterable $entities,
        Engine $engine,
        float $durationMs,
        string $operation,
    ): self {
        $list = collection($entities)->toList();
        $count = count($list);
        $table = null;
        $index = null;
        $source = '';
        $ids = [];

        if ($list !== []) {
            /** @var \Cake\Datasource\EntityInterface $first */
            $first = $list[0];
            $table = self::resolveTable($first);
            $index = self::resolveIndex($first, $table);
            $source = (string)$first->getSource();
            foreach ($list as $entity) {
                $ids[] = method_exists($entity, 'getExploratorKey')
                    ? $entity->getExploratorKey()
                    : $entity->get('id');
            }
        }

        return new self($table, [
            'operation' => $operation,
            'count' => $count,
            'index' => $index,
            'engine' => basename(str_replace('\\', '/', $engine::class)),
            'duration_ms' => $durationMs,
            'request' => [
                'source' => $source,
                'ids' => $ids,
            ],
            'response' => [
                'operation' => $operation,
                'count' => $count,
                'index' => $index,
            ],
        ]);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return \Cake\ORM\Table|null
     */
    protected static function resolveTable(EntityInterface $entity): ?Table
    {
        $source = $entity->getSource();
        if ($source === '') {
            return null;
        }

        try {
            return TableRegistry::getTableLocator()->get($source);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \Cake\ORM\Table|null $table Resolved table
     * @return string|null
     */
    protected static function resolveIndex(EntityInterface $entity, ?Table $table): ?string
    {
        if ($table instanceof Table && method_exists($table, 'searchableAs')) {
            return $table->searchableAs();
        }

        if ($table instanceof Table) {
            return $table->getTable();
        }

        $source = $entity->getSource();

        return $source !== '' ? $source : null;
    }

    /**
     * @return string
     */
    public function getOperation(): string
    {
        return (string)($this->getData('operation') ?? 'update');
    }

    /**
     * @return int
     */
    public function getCount(): int
    {
        return (int)($this->getData('count') ?? 0);
    }

    /**
     * @return string|null
     */
    public function getIndex(): ?string
    {
        $index = $this->getData('index');

        return is_string($index) ? $index : null;
    }

    /**
     * @return string
     */
    public function getEngine(): string
    {
        return (string)($this->getData('engine') ?? '');
    }

    /**
     * @return float
     */
    public function getDurationMs(): float
    {
        return (float)($this->getData('duration_ms') ?? 0.0);
    }
}
