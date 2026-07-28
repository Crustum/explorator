<?php
declare(strict_types=1);

namespace Crustum\Explorator\Contract;

/**
 * Engines that can sync index settings.
 */
interface UpdatesIndexSettings
{
    /**
     * Update the index settings for the given index.
     *
     * @param string $name Index name
     * @param array<string, mixed> $settings Settings payload
     * @return void
     */
    public function updateIndexSettings(string $name, array $settings = []): void;

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @param array<string, mixed> $settings Settings payload
     * @return array<string, mixed>
     */
    public function configureSoftDeleteFilter(array $settings = []): array;
}
