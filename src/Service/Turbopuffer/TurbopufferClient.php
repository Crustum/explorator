<?php
declare(strict_types=1);

namespace Crustum\Explorator\Service\Turbopuffer;

use Cake\Http\Client;
use Cake\Http\Client\Request;
use Cake\Http\Client\Response;
use Crustum\Explorator\Exception\ExploratorException;
use Throwable;

/**
 * Thin HTTP client for the Turbopuffer v2 API.
 */
class TurbopufferClient
{
    /**
     * @param \Cake\Http\Client $http Underlying HTTP client
     * @param array<string, mixed> $config Turbopuffer configuration
     */
    public function __construct(
        protected Client $http,
        protected array $config = [],
    ) {
    }

    /**
     * Send a request to Turbopuffer and decode the JSON response.
     *
     * @param array<string, mixed> $options Request options (e.g. `json`)
     * @return array<string, mixed>
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        $url = $this->baseUrl() . $uri;

        $headers = array_merge($this->authHeaders(), $options['headers'] ?? []);
        $timeout = $options['timeout'] ?? $this->config['timeout'] ?? 60;

        $body = null;
        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
        }

        $retries = (int)($this->config['retries'] ?? 3);
        $attempts = max(1, $retries + 1);

        $lastException = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $request = new Request($url, strtoupper($method), $headers, $body);
                $response = $this->http->send($request, ['timeout' => $timeout]);

                if ($response->getStatusCode() === 202) {
                    throw new ExploratorException('The Turbopuffer index required by this operation is still building.');
                }

                return $this->decode($response);
            } catch (ExploratorException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (!$this->shouldRetry($exception)) {
                    break;
                }
            }
        }

        throw new ExploratorException('Turbopuffer request failed: ' . $lastException->getMessage(), 0, $lastException);
    }

    /**
     * Get a Turbopuffer namespace instance.
     */
    public function namespace(string $name): TurbopufferNamespace
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name)) {
            throw new ExploratorException("Invalid Turbopuffer namespace [{$name}].");
        }

        return new TurbopufferNamespace($this, $name);
    }

    /**
     * Build the base URL for the configured region / endpoint.
     */
    protected function baseUrl(): string
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (empty($baseUrl)) {
            $baseUrl = sprintf('https://%s.turbopuffer.com', $this->config['region'] ?? 'gcp-us-central1');
        }

        return rtrim((string)$baseUrl, '/');
    }

    /**
     * Build the default authentication / accept headers.
     *
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . ($this->config['api_key'] ?? ''),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Explorator' => 'explorator',
        ];
    }

    /**
     * Determine whether a failed request should be retried.
     */
    protected function shouldRetry(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'connection')
            || str_contains($message, '408')
            || str_contains($message, '409')
            || str_contains($message, '429')
            || str_contains($message, '500')
            || str_contains($message, '502')
            || str_contains($message, '503')
            || str_contains($message, '504');
    }

    /**
     * Decode a Turbopuffer JSON response.
     *
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $body = $response->getJson();

        return is_array($body) ? $body : [];
    }
}
