<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Override;

/**
 * Observer fixture entity with $GLOBALS hooks (Feature ModelObserver*).
 */
class ObserverSearchableUser extends SearchableUser
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function shouldBeSearchable(): bool
    {
        if (array_key_exists('user.shouldBeSearchable', $GLOBALS)) {
            return (bool)$GLOBALS['user.shouldBeSearchable'];
        }

        return parent::shouldBeSearchable();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function searchIndexShouldBeUpdated(): bool
    {
        if (array_key_exists('user.searchIndexShouldBeUpdated', $GLOBALS)) {
            return (bool)$GLOBALS['user.searchIndexShouldBeUpdated'];
        }

        return parent::searchIndexShouldBeUpdated();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function wasSearchableBeforeUpdate(): bool
    {
        if (array_key_exists('user.wasSearchableBeforeUpdate', $GLOBALS)) {
            return (bool)$GLOBALS['user.wasSearchableBeforeUpdate'];
        }

        return parent::wasSearchableBeforeUpdate();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function wasSearchableBeforeDelete(): bool
    {
        if (array_key_exists('user.wasSearchableBeforeDelete', $GLOBALS)) {
            return (bool)$GLOBALS['user.wasSearchableBeforeDelete'];
        }

        return parent::wasSearchableBeforeDelete();
    }
}
