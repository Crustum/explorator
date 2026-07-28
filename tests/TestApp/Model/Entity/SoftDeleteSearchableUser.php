<?php
declare(strict_types=1);

namespace TestApp\Model\Entity;

use Cake\I18n\DateTime;
use Override;

/**
 * Soft-delete aware searchable user for Feature SearchableTest.
 */
class SoftDeleteSearchableUser extends SearchableUser
{
    /**
     * @inheritDoc
     */
    #[Override]
    public function shouldBeSearchable(): bool
    {
        return $this->get('published_at') !== null && !$this->trashed();
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function wasSearchableBeforeUpdate(): bool
    {
        return $this->getOriginal('published_at') !== null
            && $this->getOriginal('deleted') === null;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public function wasSearchableBeforeDelete(): bool
    {
        return $this->get('published_at') !== null
            && (
                array_key_exists('deleted', $this->getOriginalValues())
                    ? $this->getOriginal('deleted', false) === null
                    : true
            );
    }

    /**
     * @param \Cake\I18n\DateTime|null $value Published at
     * @return $this
     */
    public function markPublished(?DateTime $value = null)
    {
        $this->set('published_at', $value ?? new DateTime());

        return $this;
    }
}
