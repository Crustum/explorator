<?php
declare(strict_types=1);

namespace Crustum\Explorator;

use ArrayIterator;
use Cake\Collection\CollectionInterface;
use Cake\Datasource\Paging\PaginatedInterface;
use Cake\Datasource\Paging\PaginatedResultSet;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Table;
use Closure;
use Crustum\Explorator\Engines\Engine;

/**
 * Fluent Explorator search builder (no Macroable).
 */
class Builder
{
    /**
     * The searchable table.
     *
     * @var \Cake\ORM\Table
     */
    public Table $table;

    /**
     * The query expression.
     *
     * @var string
     */
    public string $query;

    /**
     * Optional callback before search execution (engine-specific).
     *
     * @var \Closure|null
     */
    public ?Closure $callback = null;

    /**
     * Optional callback before model/query hydration.
     *
     * @var \Closure|null
     */
    public ?Closure $queryCallback = null;

    /**
     * Optional callback after raw search.
     *
     * @var \Closure|null
     */
    public ?Closure $afterRawSearchCallback = null;

    /**
     * Custom index name.
     *
     * @var string|null
     */
    public ?string $index = null;

    /**
     * Where constraints.
     *
     * @var list<array{field: string, operator: string, value: mixed}>
     */
    public array $wheres = [];

    /**
     * Where-in constraints.
     *
     * @var array<string, list<mixed>>
     */
    public array $whereIns = [];

    /**
     * Where-not-in constraints.
     *
     * @var array<string, list<mixed>>
     */
    public array $whereNotIns = [];

    /**
     * Result limit.
     *
     * @var int|null
     */
    public ?int $limit = null;

    /**
     * Order clauses.
     *
     * @var list<array{column: string, direction: string}>
     */
    public array $orders = [];

    /**
     * Extra engine options.
     *
     * @var array<string, mixed>
     */
    public array $options = [];

    /**
     * Engine manager used to resolve the active driver.
     *
     * @var \Crustum\Explorator\EngineManager|null
     */
    protected ?EngineManager $engineManager = null;

    /**
     * @param \Cake\ORM\Table $table Searchable table
     * @param string $query Search query
     * @param \Closure|null $callback Engine callback
     * @param bool $softDelete Whether soft-delete metadata is applied
     * @param \Crustum\Explorator\EngineManager|null $engineManager Engine manager
     */
    public function __construct(
        Table $table,
        string $query = '',
        ?Closure $callback = null,
        bool $softDelete = false,
        ?EngineManager $engineManager = null,
    ) {
        $this->table = $table;
        $this->query = $query;
        $this->callback = $callback;
        $this->engineManager = $engineManager;

        if ($softDelete) {
            $this->wheres[] = [
                'field' => '__soft_deleted',
                'operator' => '=',
                'value' => 0,
            ];
        }
    }

    /**
     * @param \Crustum\Explorator\EngineManager $engineManager Engine manager
     * @return $this
     */
    public function setEngineManager(EngineManager $engineManager)
    {
        $this->engineManager = $engineManager;

        return $this;
    }

    /**
     * @param string $index Index name
     * @return $this
     */
    public function within(string $index)
    {
        $this->index = $index;

        return $this;
    }

    /**
     * @param string $field Field name
     * @param mixed $operator Operator or value when only two args
     * @param mixed|null $value Compared value
     * @return $this
     */
    public function where(string $field, mixed $operator, mixed $value = null)
    {
        $this->wheres[] = [
            'field' => $field,
            'operator' => func_num_args() === 2 ? '=' : (string)$operator,
            'value' => func_num_args() === 2 ? $operator : $value,
        ];

        return $this;
    }

    /**
     * @param string $field Field name
     * @param iterable<mixed> $values Values
     * @return $this
     */
    public function whereIn(string $field, iterable $values)
    {
        $this->whereIns[$field] = array_values(is_array($values) ? $values : iterator_to_array($values));

        return $this;
    }

    /**
     * @param string $field Field name
     * @param iterable<mixed> $values Values
     * @return $this
     */
    public function whereNotIn(string $field, iterable $values)
    {
        $this->whereNotIns[$field] = array_values(is_array($values) ? $values : iterator_to_array($values));

        return $this;
    }

    /**
     * @return $this
     */
    public function withTrashed()
    {
        $this->wheres = array_values(array_filter(
            $this->wheres,
            static fn(array $where): bool => $where['field'] !== '__soft_deleted',
        ));

        return $this;
    }

    /**
     * @return $this
     */
    public function onlyTrashed()
    {
        $this->withTrashed();
        $this->wheres[] = [
            'field' => '__soft_deleted',
            'operator' => '=',
            'value' => 1,
        ];

        return $this;
    }

    /**
     * @param int $limit Limit
     * @return $this
     */
    public function take(int $limit)
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * @param string $column Column
     * @param string $direction Direction
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc')
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction) === 'asc' ? 'asc' : 'desc',
        ];

        return $this;
    }

    /**
     * @param string $column Column
     * @return $this
     */
    public function orderByDesc(string $column)
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * @param string|null $column Created-at column
     * @return $this
     */
    public function latest(?string $column = null)
    {
        $column ??= $this->createdAtColumn();

        return $this->orderBy($column, 'desc');
    }

    /**
     * @param string|null $column Created-at column
     * @return $this
     */
    public function oldest(?string $column = null)
    {
        $column ??= $this->createdAtColumn();

        return $this->orderBy($column, 'asc');
    }

    /**
     * @param array<string, mixed> $options Options
     * @return $this
     */
    public function options(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @param callable $callback Query callback
     * @return $this
     */
    public function query(callable $callback)
    {
        $this->queryCallback = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * @param callable $callback Builder callback
     * @return $this
     */
    public function tap(callable $callback)
    {
        $callback($this);

        return $this;
    }

    /**
     * @return mixed
     */
    public function raw(): mixed
    {
        $engine = $this->engine();

        return SearchInstrumentation::run(
            $this,
            $engine,
            fn(): mixed => $engine->search($this),
        );
    }

    /**
     * @param callable $callback After-raw callback
     * @return $this
     */
    public function withRawResults(callable $callback)
    {
        $this->afterRawSearchCallback = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * @return \Cake\Collection\CollectionInterface
     */
    public function keys(): CollectionInterface
    {
        return $this->engine()->keys($this);
    }

    /**
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function first(): mixed
    {
        return $this->get()->first();
    }

    /**
     * @return \Cake\Datasource\ResultSetInterface<\Cake\Datasource\EntityInterface>
     */
    public function get(): ResultSetInterface
    {
        return $this->engine()->get($this);
    }

    /**
     * @return \Cake\Collection\CollectionInterface<\Cake\Datasource\EntityInterface>
     */
    public function cursor(): CollectionInterface
    {
        return $this->engine()->cursor($this);
    }

    /**
     * Paginate engine results into Cake's PaginatedInterface shape.
     *
     * @param int|null $perPage Page size
     * @param string $pageName Page query name (unused for engine paging)
     * @param int|null $page Page number
     * @return \Cake\Datasource\Paging\PaginatedInterface
     */
    public function paginate(?int $perPage = null, string $pageName = 'page', ?int $page = null): PaginatedInterface
    {
        unset($pageName);
        $engine = $this->engine();
        $page = max(1, $page ?? 1);
        $perPage ??= 15;

        $rawResults = SearchInstrumentation::run(
            $this,
            $engine,
            fn(): mixed => $engine->paginate($this, $perPage, $page),
            'paginate',
            $page,
            $perPage,
        );
        $mapped = $engine->map(
            $this,
            $this->applyAfterRawSearchCallback($rawResults),
        );
        $total = $engine->getTotalCount($rawResults);

        return $this->toPaginatedResultSet($mapped->toList(), $perPage, $page, $total);
    }

    /**
     * Simple paginate mapped entities (has-more style, still uses engine total when available).
     *
     * @param int|null $perPage Page size
     * @param string $pageName Page query name
     * @param int|null $page Page number
     * @return \Cake\Datasource\Paging\PaginatedInterface
     */
    public function simplePaginate(?int $perPage = null, string $pageName = 'page', ?int $page = null): PaginatedInterface
    {
        unset($pageName);
        $engine = $this->engine();
        $page = max(1, $page ?? 1);
        $perPage ??= 15;

        if (method_exists($engine, 'simplePaginate')) {
            /** @var array{results: list<\Cake\Datasource\EntityInterface>, total: int|null, hasMore: bool} $rawResults */
            $rawResults = SearchInstrumentation::run(
                $this,
                $engine,
                fn(): mixed => $engine->simplePaginate($this, $perPage, $page),
                'paginate',
                $page,
                $perPage,
            );
            $mapped = $engine->map(
                $this,
                $this->applyAfterRawSearchCallback($rawResults),
            );
            $items = $mapped->toList();
            $count = count($items);

            return new PaginatedResultSet(new ArrayIterator($items), [
                'count' => $count,
                'totalCount' => null,
                'perPage' => $perPage,
                'currentPage' => $page,
                'requestedPage' => $page,
                'pageCount' => null,
                'hasPrevPage' => $page > 1,
                'hasNextPage' => (bool)$rawResults['hasMore'],
                'start' => $count > 0 ? (($page - 1) * $perPage) + 1 : 0,
                'end' => $count > 0 ? (($page - 1) * $perPage) + $count : 0,
            ]);
        }

        $rawResults = SearchInstrumentation::run(
            $this,
            $engine,
            fn(): mixed => $engine->paginate($this, $perPage, $page),
            'paginate',
            $page,
            $perPage,
        );
        $mapped = $engine->map(
            $this,
            $this->applyAfterRawSearchCallback($rawResults),
        );
        $total = $engine->getTotalCount($rawResults);

        return $this->toPaginatedResultSet($mapped->toList(), $perPage, $page, $total);
    }

    /**
     * Simple paginate with raw engine payload as items.
     *
     * @param int|null $perPage Page size
     * @param string $pageName Page query name
     * @param int|null $page Page number
     * @return \Cake\Datasource\Paging\PaginatedInterface
     */
    public function simplePaginateRaw(?int $perPage = null, string $pageName = 'page', ?int $page = null): PaginatedInterface
    {
        unset($pageName);
        $engine = $this->engine();
        $page = max(1, $page ?? 1);
        $perPage ??= 15;

        $rawResults = $this->applyAfterRawSearchCallback(
            SearchInstrumentation::run(
                $this,
                $engine,
                fn(): mixed => $engine->paginate($this, $perPage, $page),
                'paginate',
                $page,
                $perPage,
            ),
        );
        $total = $engine->getTotalCount($rawResults);
        $items = is_array($rawResults) ? $rawResults : [$rawResults];

        return $this->toPaginatedResultSet($items, $perPage, $page, $total);
    }

    /**
     * Length-aware paginate with raw engine payload as items.
     *
     * @param int|null $perPage Page size
     * @param string $pageName Page query name
     * @param int|null $page Page number
     * @return \Cake\Datasource\Paging\PaginatedInterface
     */
    public function paginateRaw(?int $perPage = null, string $pageName = 'page', ?int $page = null): PaginatedInterface
    {
        return $this->simplePaginateRaw($perPage, $pageName, $page);
    }

    /**
     * @param list<mixed> $items Page items
     * @param int $perPage Page size
     * @param int $page Current page
     * @param int $total Total hits
     * @return \Cake\Datasource\Paging\PaginatedInterface
     */
    protected function toPaginatedResultSet(array $items, int $perPage, int $page, int $total): PaginatedInterface
    {
        $pageCount = max(1, (int)ceil($total / $perPage));
        $count = count($items);

        return new PaginatedResultSet(new ArrayIterator($items), [
            'count' => $count,
            'totalCount' => $total,
            'perPage' => $perPage,
            'currentPage' => $page,
            'requestedPage' => $page,
            'pageCount' => $pageCount,
            'hasPrevPage' => $page > 1,
            'hasNextPage' => $perPage * $page < $total,
            'start' => $count > 0 ? (($page - 1) * $perPage) + 1 : 0,
            'end' => $count > 0 ? (($page - 1) * $perPage) + $count : 0,
        ]);
    }

    /**
     * Apply the after-raw-search callback when present.
     *
     * @param mixed $results Raw results
     * @return mixed
     */
    public function applyAfterRawSearchCallback(mixed $results): mixed
    {
        if ($this->afterRawSearchCallback instanceof Closure) {
            return ($this->afterRawSearchCallback)($results) ?? $results;
        }

        return $results;
    }

    /**
     * Resolve the active Explorator engine.
     *
     * @return \Crustum\Explorator\Engines\Engine
     */
    public function engine(): Engine
    {
        if (method_exists($this->table, 'searchableUsing')) {
            return $this->table->searchableUsing();
        }

        $manager = $this->engineManager ?? new EngineManager();

        return $manager->engine();
    }

    /**
     * @return string
     */
    protected function createdAtColumn(): string
    {
        if (method_exists($this->table, 'getCreatedAtColumn')) {
            return (string)$this->table->getCreatedAtColumn();
        }

        return 'created';
    }
}
