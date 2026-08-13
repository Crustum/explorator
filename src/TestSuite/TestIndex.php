<?php
declare(strict_types=1);

namespace Crustum\Explorator\TestSuite;

/**
 * Stubbed search index handed to a Builder's engine callback.
 *
 * {@see \Crustum\Explorator\Builder::$callback} receives an engine-specific
 * index object that exposes `rawSearch()`. TestEngine passes a TestIndex so the
 * callback path of searchable tables runs end-to-end against recorded results
 * instead of short-circuiting to an empty array.
 */
final class TestIndex
{
    /**
     * @param list<array<string, mixed>> $hits Raw hits returned by rawSearch()
     */
    public function __construct(
        private array $hits,
    ) {
    }

    /**
     * Return the stubbed raw hits (optionally adjusted by $params).
     *
     * @param string $query Search query (unused — results are stubbed)
     * @param array<string, mixed> $params Search params (e.g. limit/filter)
     * @return array{hits: list<array<string, mixed>>}
     */
    public function rawSearch(string $query, array $params = []): array
    {
        unset($query);
        $hits = $this->hits;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 0;
        if ($limit > 0) {
            $hits = array_slice($hits, 0, $limit);
        }

        return ['hits' => $hits];
    }
}
