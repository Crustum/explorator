<?php
declare(strict_types=1);

namespace Crustum\Explorator\Database\Expression;

use Cake\Database\Expression\IdentifierExpression;
use Cake\Database\ExpressionInterface;
use Cake\Database\ValueBinder;
use Closure;

/**
 * MySQL MATCH (columns) AGAINST (value IN NATURAL LANGUAGE MODE).
 */
class MatchAgainstExpression implements ExpressionInterface
{
    /**
     * @var list<\Cake\Database\Expression\IdentifierExpression>
     */
    protected array $columns;

    /**
     * @param list<string> $columns Qualified column names
     * @param string $search Search string
     */
    public function __construct(array $columns, protected string $search)
    {
        $this->columns = array_map(
            static fn(string $column): IdentifierExpression => new IdentifierExpression($column),
            $columns,
        );
    }

    /**
     * @inheritDoc
     */
    public function sql(ValueBinder $binder): string
    {
        $columnSql = array_map(
            static fn(IdentifierExpression $column): string => $column->sql($binder),
            $this->columns,
        );
        $placeholder = $binder->placeholder('explorator_ft');
        $binder->bind($placeholder, $this->search, 'string');

        return sprintf(
            'MATCH (%s) AGAINST (%s IN NATURAL LANGUAGE MODE)',
            implode(', ', $columnSql),
            $placeholder,
        );
    }

    /**
     * @inheritDoc
     */
    public function traverse(Closure $callback)
    {
        foreach ($this->columns as $column) {
            $callback($column);
            $column->traverse($callback);
        }

        return $this;
    }
}
