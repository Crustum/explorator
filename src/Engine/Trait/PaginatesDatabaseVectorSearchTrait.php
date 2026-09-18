<?php
declare(strict_types=1);

namespace Crustum\Explorator\Engine\Trait;

use Crustum\Explorator\Builder;
use Crustum\Explorator\Exception\ExploratorException;

/**
 * Database semantic / hybrid pagination (pgvector).
 *
 * @method \Cake\Collection\Collection hybridSearchModels(\Crustum\Explorator\Builder $builder, int $limit)
 * @method \Cake\ORM\Query\SelectQuery buildSemanticSearchQuery(\Crustum\Explorator\Builder $builder)
 */
trait PaginatesDatabaseVectorSearchTrait
{
    /**
     * Paginate a hybrid search using a bounded fused result window.
     *
     * @return array{results: list<\Cake\Datasource\EntityInterface>, total: int}
     */
    protected function paginateHybridSearch(Builder $builder, int $perPage, string $pageName, int $page): array
    {
        [$page, $perPage, $maximum] = $this->hybridPaginationWindow($builder, $perPage, $pageName, $page);

        $models = $this->hybridSearchModels($builder, $maximum)->toList();

        $total = count($models);

        $results = array_slice($models, ($page - 1) * $perPage, $perPage);

        return ['results' => $results, 'total' => $total];
    }

    /**
     * Simply paginate a hybrid search using a bounded fused result window.
     *
     * @return array{results: list<\Cake\Datasource\EntityInterface>, total: int|null, hasMore: bool}
     */
    protected function simplePaginateHybridSearch(Builder $builder, int $perPage, string $pageName, int $page): array
    {
        [$page, $perPage, $maximum] = $this->hybridPaginationWindow($builder, $perPage, $pageName, $page);

        $models = $this->hybridSearchModels($builder, $maximum)->toList();
        $total = count($models);

        $results = array_slice($models, ($page - 1) * $perPage, $perPage);
        $hasMore = $page * $perPage < $total;

        return ['results' => $results, 'total' => null, 'hasMore' => $hasMore];
    }

    /**
     * Resolve and validate a hybrid pagination window.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected function hybridPaginationWindow(Builder $builder, int $perPage, string $pageName, int $page): array
    {
        [$page, $perPage, $offset] = $this->resolvePaginationState($builder, $perPage, $pageName, $page);

        if ($offset + $perPage > 1000) {
            throw new ExploratorException('Database hybrid search results may not be paginated beyond 1,000 records.');
        }

        return [$page, $perPage, min($builder->limit ?? 1000, 1000)];
    }

    /**
     * Paginate a semantic search.
     *
     * @return array{results: list<\Cake\Datasource\EntityInterface>, total: int}
     */
    protected function paginateSemanticSearch(Builder $builder, int $perPage, string $pageName, int $page): array
    {
        [$page, $perPage, $offset] = $this->resolvePaginationState($builder, $perPage, $pageName, $page);

        $maximum = $this->resolveResultLimit($builder);

        $query = $this->buildSemanticSearchQuery($builder);

        $total = min((int)$query->count(), $maximum);

        $models = $offset >= $maximum
            ? []
            : $query->offset($offset)->limit(min($perPage, $maximum - $offset))->all()->toList();

        return ['results' => $models, 'total' => $total];
    }

    /**
     * Simply paginate a semantic search.
     *
     * @return array{results: list<\Cake\Datasource\EntityInterface>, total: int|null, hasMore: bool}
     */
    protected function simplePaginateSemanticSearch(Builder $builder, int $perPage, string $pageName, int $page): array
    {
        [$page, $perPage, $offset] = $this->resolvePaginationState($builder, $perPage, $pageName, $page);

        $limit = min($perPage + 1, max(0, $this->resolveResultLimit($builder) - $offset));

        $models = $limit === 0
            ? []
            : $this->buildSemanticSearchQuery($builder)->offset($offset)->limit($limit)->all()->toList();

        $hasMore = count($models) > $perPage;

        return ['results' => array_slice($models, 0, $perPage), 'total' => null, 'hasMore' => $hasMore];
    }

    /**
     * Resolve the current pagination values.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    protected function resolvePaginationState(Builder $builder, int $perPage, string $pageName, int $page): array
    {
        [$page, $perPage] = [
            max(1, $page),
            max(1, $perPage),
        ];

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    /**
     * Resolve the maximum number of results for the search.
     */
    protected function resolveResultLimit(Builder $builder): int
    {
        return $builder->limit === null
            ? PHP_INT_MAX
            : max(0, $builder->limit);
    }
}
