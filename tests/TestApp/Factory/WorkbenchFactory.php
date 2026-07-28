<?php
declare(strict_types=1);

namespace TestApp\Factory;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use TestApp\Model\Table\BookmarksTable;
use TestApp\Model\Table\ChirpsTable;
use TestApp\Model\Table\SearchableUsersTable;

/**
 * Lightweight TestApp seed helpers.
 */
final class WorkbenchFactory
{
    /**
     * @param array<string, mixed> $data User attributes
     * @return \Cake\Datasource\EntityInterface
     */
    public static function searchableUser(array $data = []): EntityInterface
    {
        $table = self::searchableUsersTable();
        $entity = $table->newEntity(array_merge([
            'name' => 'Example User',
            'email' => sprintf('user-%s@verified.test', uniqid('', true)),
            'age' => null,
        ], $data));

        return $table->saveOrFail($entity);
    }

    /**
     * @param array<string, mixed> $data Chirp attributes
     * @return \Cake\Datasource\EntityInterface
     */
    public static function chirp(array $data = []): EntityInterface
    {
        $table = self::chirpsTable();
        $entity = $table->newEntity(array_merge([
            'content' => 'Example chirp',
            'explorator_id' => uniqid('chirp-', true),
        ], $data));

        return $table->saveOrFail($entity);
    }

    /**
     * @param array<string, mixed> $data Bookmark attributes
     * @param array<string, mixed>|null $chirpData Optional chirp attributes when chirp_id omitted
     * @return \Cake\Datasource\EntityInterface
     */
    public static function bookmark(array $data = [], ?array $chirpData = null): EntityInterface
    {
        if (!isset($data['chirp_id'])) {
            $data['chirp_id'] = self::chirp($chirpData ?? [])->id;
        }

        $table = self::bookmarksTable();
        $entity = $table->newEntity(array_merge([
            'label' => 'Example bookmark',
        ], $data));

        return $table->saveOrFail($entity);
    }

    /**
     * @return \TestApp\Model\Table\SearchableUsersTable
     */
    public static function searchableUsersTable(): SearchableUsersTable
    {
        return self::table('SearchableUsers', SearchableUsersTable::class, 'searchable_users');
    }

    /**
     * @return \TestApp\Model\Table\ChirpsTable
     */
    public static function chirpsTable(): ChirpsTable
    {
        return self::table('Chirps', ChirpsTable::class, 'chirps');
    }

    /**
     * @return \TestApp\Model\Table\BookmarksTable
     */
    public static function bookmarksTable(): BookmarksTable
    {
        return self::table('Bookmarks', BookmarksTable::class, 'bookmarks');
    }

    /**
     * @param string $alias Locator alias
     * @param class-string<\Cake\ORM\Table> $className Table class
     * @param string $tableName DB table
     * @return \Cake\ORM\Table
     */
    private static function table(string $alias, string $className, string $tableName): Table
    {
        $locator = TableRegistry::getTableLocator();
        if ($locator->exists($alias)) {
            /** @var \Cake\ORM\Table $existing */
            $existing = $locator->get($alias);

            return $existing;
        }

        /** @var \Cake\ORM\Table $table */
        $table = new $className([
            'alias' => $alias,
            'table' => $tableName,
            'registryAlias' => $alias,
        ]);
        $locator->set($alias, $table);

        return $table;
    }
}
