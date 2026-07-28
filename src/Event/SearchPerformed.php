<?php
declare(strict_types=1);

namespace Crustum\Explorator\Event;

use Cake\Event\Event;
use Cake\ORM\Table;
use Crustum\Explorator\Builder;
use Crustum\Explorator\Engines\Engine;
use Throwable;

/**
 * Fired after a Explorator search (or paginated search) completes.
 *
 * @extends \Cake\Event\Event<\Cake\ORM\Table>
 */
class SearchPerformed extends Event
{
    public const NAME = 'Explorator.SearchPerformed';

    /**
     * Max result ids included in the response payload.
     */
    public const RESPONSE_IDS_LIMIT = 50;

    /**
     * @param \Cake\ORM\Table $subject Table that was searched
     * @param array{
     *     query: string,
     *     index: string|null,
     *     engine: string,
     *     hits: int|null,
     *     duration_ms: float,
     *     operation: string,
     *     page: int|null,
     *     per_page: int|null,
     *     request: array<string, mixed>,
     *     response: array<string, mixed>
     * } $data Event payload
     */
    public function __construct(Table $subject, array $data)
    {
        parent::__construct(self::NAME, $subject, $data);
    }

    /**
     * Build and return a SearchPerformed event from a builder + engine result.
     *
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param \Crustum\Explorator\Engines\Engine $engine Engine that ran the search
     * @param mixed $results Raw engine results
     * @param float $durationMs Duration in milliseconds
     * @param string $operation Operation name (`search` or `paginate`)
     * @param int|null $page Page number when paginating
     * @param int|null $perPage Page size when paginating
     * @return self
     */
    public static function fromSearch(
        Builder $builder,
        Engine $engine,
        mixed $results,
        float $durationMs,
        string $operation = 'search',
        ?int $page = null,
        ?int $perPage = null,
    ): self {
        $hits = null;
        try {
            $hits = $engine->getTotalCount($results);
        } catch (Throwable) {
        }

        $index = $builder->index;
        if ($index === null && method_exists($builder->table, 'searchableAs')) {
            $index = $builder->table->searchableAs();
        }

        $ids = [];
        try {
            $keyName = method_exists($builder->table, 'getExploratorKeyName')
                ? $builder->table->getExploratorKeyName()
                : 'id';
            $ids = $engine->mapIdsFrom($results, $keyName)->take(self::RESPONSE_IDS_LIMIT)->toList();
        } catch (Throwable) {
        }

        return new self($builder->table, [
            'query' => $builder->query,
            'index' => $index,
            'engine' => basename(str_replace('\\', '/', $engine::class)),
            'hits' => $hits,
            'duration_ms' => $durationMs,
            'operation' => $operation,
            'page' => $page,
            'per_page' => $perPage,
            'request' => self::requestFromBuilder($builder, $index),
            'response' => [
                'hits' => $hits,
                'ids' => $ids,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * @param \Crustum\Explorator\Builder $builder Search builder
     * @param string|null $index Resolved index name
     * @return array<string, mixed>
     */
    protected static function requestFromBuilder(Builder $builder, ?string $index): array
    {
        return [
            'query' => $builder->query,
            'index' => $index,
            'wheres' => $builder->wheres,
            'whereIns' => $builder->whereIns,
            'whereNotIns' => $builder->whereNotIns,
            'limit' => $builder->limit,
            'orders' => $builder->orders,
            'options' => self::sanitizeOptions($builder->options),
        ];
    }

    /**
     * @param array<string, mixed> $options Builder options
     * @return array<string, mixed>
     */
    protected static function sanitizeOptions(array $options): array
    {
        $sanitized = [];
        foreach ($options as $key => $value) {
            $sanitized[$key] = self::sanitizeOptionValue($value);
        }

        return $sanitized;
    }

    /**
     * @param mixed $value Option value
     * @return mixed
     */
    protected static function sanitizeOptionValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (
                $value !== [] && array_is_list($value) && array_all(
                    $value,
                    static fn(mixed $item): bool => is_int($item) || is_float($item),
                ) && count($value) > 8
            ) {
                return [
                    '__vector' => true,
                    'dimensions' => count($value),
                ];
            }

            $nested = [];
            foreach ($value as $key => $item) {
                $nested[$key] = self::sanitizeOptionValue($item);
            }

            return $nested;
        }

        return $value;
    }

    /**
     * @return string
     */
    public function getQuery(): string
    {
        return (string)($this->getData('query') ?? '');
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
     * @return int|null
     */
    public function getHits(): ?int
    {
        $hits = $this->getData('hits');

        return is_int($hits) ? $hits : null;
    }

    /**
     * @return float
     */
    public function getDurationMs(): float
    {
        return (float)($this->getData('duration_ms') ?? 0.0);
    }

    /**
     * @return string
     */
    public function getOperation(): string
    {
        return (string)($this->getData('operation') ?? 'search');
    }

    /**
     * @return int|null
     */
    public function getPage(): ?int
    {
        $page = $this->getData('page');

        return is_int($page) ? $page : null;
    }

    /**
     * @return int|null
     */
    public function getPerPage(): ?int
    {
        $perPage = $this->getData('per_page');

        return is_int($perPage) ? $perPage : null;
    }
}
