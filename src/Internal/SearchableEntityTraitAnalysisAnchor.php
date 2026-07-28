<?php
declare(strict_types=1);

namespace Crustum\Explorator\Internal;

use Cake\ORM\Entity;
use Crustum\Explorator\Model\Trait\SearchableEntityTrait;

/**
 * @internal PHPStan analysis anchor. Do not extend in applications — use SearchableEntityTrait.
 */
final class SearchableEntityTraitAnalysisAnchor extends Entity
{
    use SearchableEntityTrait;
}
