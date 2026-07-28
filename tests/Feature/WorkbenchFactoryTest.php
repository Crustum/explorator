<?php
declare(strict_types=1);

namespace Crustum\Explorator\Test\Feature;

use Cake\TestSuite\TestCase;
use Crustum\Explorator\Test\Factory\BookmarkFactory;
use Crustum\Explorator\Test\Factory\ChirpFactory;
use Crustum\Explorator\Test\Factory\SearchableUserFactory;
use TestApp\Model\Entity\Bookmark;
use TestApp\Model\Entity\Chirp;
use TestApp\Model\Entity\SearchableUser;

/**
 * Fixture-factory smoke.
 */
class WorkbenchFactoryTest extends TestCase
{
    /**
     * @return void
     */
    public function testSearchableUserFactoryPersist(): void
    {
        $user = SearchableUserFactory::make([
            'name' => 'Factory Persist User',
            'email' => 'factory-persist@verified.test',
        ])->persist();

        $this->assertInstanceOf(SearchableUser::class, $user);
        $this->assertNotEmpty($user->id);
        $this->assertSame('Factory Persist User', $user->name);
    }

    /**
     * @return void
     */
    public function testChirpFactoryPersist(): void
    {
        $chirp = ChirpFactory::make([
            'content' => 'This chirp is searchable',
        ])->persist();

        $this->assertInstanceOf(Chirp::class, $chirp);
        $this->assertNotEmpty($chirp->explorator_id);
        $this->assertSame('This chirp is searchable', $chirp->content);
    }

    /**
     * @return void
     */
    public function testBookmarkFactoryPersistWithChirp(): void
    {
        $bookmark = BookmarkFactory::make([
            'label' => 'cakephp',
        ])
            ->withChirp(['content' => 'This chirp is searchable'])
            ->persist();

        $this->assertInstanceOf(Bookmark::class, $bookmark);
        $this->assertSame('cakephp', $bookmark->label);
        $this->assertNotEmpty($bookmark->chirp_id);
    }
}
