<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DockerEngine
{
    private ?string $version = null;

    /** @return array<string, mixed>|null */
    public function inspect(string $path): ?array
    {
        return $this->request('GET', $path, allowMissing: true);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(string $path, array $payload = [], ?int $timeout = null): array
    {
        return $this->request('POST', $path, $payload, timeout: $timeout) ?? [];
    }

    /** Removes a resource, tolerating one that is already gone. */
    public function remove(string $path): void
    {
        $this->request('DELETE', $path, allowMissing: true);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, array $payload = [], bool $allowMissing = false, ?int $timeout = null): ?array
    {
        if ($this->version === null) {
            $response = $this->client()->get('/version');
            if (! $response->successful()) {
                throw new RuntimeException('Docker version negotiation failed.');
            }
            $version = $response->json('ApiVersion');
            $minimum = $response->json('MinAPIVersion', '1.40');
            if (! is_string($version) || ! preg_match('/\A1\.\d+\z/', $version)
                || version_compare($version, '1.48', '<') || version_compare($minimum, '1.54', '>')) {
                throw new RuntimeException('The Docker API version is unsupported.');
            }
            $this->version = version_compare($version, '1.54', '>') ? '1.54' : $version;
        }

        $response = $this->client($timeout)->send($method, '/v'.$this->version.$path, $method === 'POST' ? ['json' => (object) $payload] : []);
        if ($allowMissing && $response->status() === 404) {
            return null;
        }
        if (! $response->successful() && $response->status() !== 304) {
            throw new RuntimeException('Docker request failed with HTTP '.$response->status().'.');
        }

        return $response->json() ?? [];
    }

    private function client(?int $timeout = null): PendingRequest
    {
        $socket = config('environments.runtime.socket');
        $url = config('environments.runtime.url');
        $options = ['allow_redirects' => false];

        if (is_string($socket) && $socket !== '') {
            $options['curl'] = [CURLOPT_UNIX_SOCKET_PATH => $socket];
            $url = 'http://localhost';
        } else {
            if (! str_starts_with($url, 'https://') || ! config('environments.runtime.tls_cert') || ! config('environments.runtime.tls_key')) {
                throw new RuntimeException('Remote Docker requires mutual TLS.');
            }
            $options['verify'] = config('environments.runtime.tls_ca') ?: true;
            $options['cert'] = config('environments.runtime.tls_cert');
            $options['ssl_key'] = config('environments.runtime.tls_key');
        }

        return Http::baseUrl($url)->acceptJson()->connectTimeout(3)->timeout($timeout ?? 10)->withOptions($options);
    }
}
