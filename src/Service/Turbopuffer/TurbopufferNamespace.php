<?php
declare(strict_types=1);

namespace Crustum\Explorator\Service\Turbopuffer;

/**
 * Represents a single Turbopuffer namespace (index).
 */
class TurbopufferNamespace
{
    /**
     * @param \Crustum\Explorator\Service\Turbopuffer\TurbopufferClient $client Parent client
     * @param string $name Namespace name
     */
    public function __construct(
        protected TurbopufferClient $client,
        protected string $name,
    ) {
    }

    /**
     * Query the namespace.
     *
     * @param array<string, mixed> $parameters Query parameters
     * @return array<string, mixed>
     */
    public function query(array $parameters): array
    {
        return $this->client->request('POST', $this->uri() . '/query', ['json' => $parameters]);
    }

    /**
     * Write / upsert documents to the namespace.
     *
     * @param array<string, mixed> $parameters Write parameters
     * @return array<string, mixed>
     */
    public function write(array $parameters): array
    {
        return $this->client->request('POST', $this->uri(), ['json' => $parameters]);
    }

    /**
     * Delete the namespace.
     *
     * @return array<string, mixed>
     */
    public function delete(): array
    {
        return $this->client->request('DELETE', $this->uri());
    }

    /**
     * Get the namespace API URI.
     */
    protected function uri(): string
    {
        return '/v2/namespaces/' . rawurlencode($this->name);
    }
}
