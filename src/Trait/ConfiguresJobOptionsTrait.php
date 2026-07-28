<?php
declare(strict_types=1);

namespace Crustum\Explorator\Trait;

use Cake\Core\Configure;

/**
 * Apply Explorator job options from Configure.
 */
trait ConfiguresJobOptionsTrait
{
    /**
     * @return array{tries?: int, maxExceptions?: int}
     */
    protected function exploratorJobOptions(): array
    {
        $options = [];
        $tries = Configure::read('Explorator.jobs.tries');
        if (is_numeric($tries)) {
            $options['tries'] = (int)$tries;
        }

        $maxExceptions = Configure::read('Explorator.jobs.maxExceptions');
        if (is_numeric($maxExceptions)) {
            $options['maxExceptions'] = (int)$maxExceptions;
        }

        return $options;
    }
}
