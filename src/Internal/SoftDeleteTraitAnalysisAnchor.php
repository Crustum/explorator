<?php
declare(strict_types=1);

namespace Crustum\Explorator\Internal;

use Cake\ORM\Table;
use Crustum\Explorator\Model\Trait\SoftDeleteTrait;

/**
 * @internal PHPStan analysis anchor. Do not extend in applications — use SoftDeleteTrait.
 */
final class SoftDeleteTraitAnalysisAnchor extends Table
{
    use SoftDeleteTrait;
}
