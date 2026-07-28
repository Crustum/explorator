<?php
declare(strict_types=1);

namespace Crustum\Explorator\Internal;

use Cake\ORM\Table;
use Crustum\Explorator\Model\Trait\SearchableTrait;

/**
 * @internal PHPStan analysis anchor. Do not extend in applications — use SearchableTrait.
 */
final class SearchableTraitAnalysisAnchor extends Table
{
    use SearchableTrait;
}
