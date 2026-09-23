<?php

namespace WursterMedien\SocialHub\Hub;

use Composer\InstalledVersions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Statamic\Statamic;
use Throwable;

/**
 * Einziger Ort mit HTTP-Aufrufen an die API v1 des Social Hubs.
 *
 * Authentifizierung per Bearer-Key der Seite. Zusätzlich werden die Versionen
 * von Addon und Statamic mitgeschickt, damit der Hub veraltete Seiten erkennt.
 */
class HubClient
{
    public const PACKAGE = 'wurster-medien/statamic-social-hub';

    /**
     * @return array{ok?: bool, hub?: array<string, mixed>, site?: array<string, mixed>}
     */
    public function ping(): array
    {
        return $this->get('api/v1/ping');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        return array_values($this->get('api/v1/accounts')['data'] ?? []);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function feed(string $handle, int $limit): array
    {
        $response = $this->get('api/v1/feeds/'.rawurlencode($handle), ['limit' => $limit]);

        return [
            'data' => array_values($response['data'] ?? []),
            'meta' => $response['meta'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function media(string $handle, string $id): ?array
    {
        return $this->get('api/v1/feeds/'.rawurlencode($handle).'/media/'.rawurlencode($id))['data'] ?? null;
    }

    /**
     * Legt einen Post an bzw. aktualisiert ihn (idempotent über source_reference).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPost(array $payload): array
    {
        return $this->send('POST', 'api/v1/posts', $payload)['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function post(string $id): array
    {
        return $this->get('api/v1/posts/'.rawurlencode($id))['data'] ?? [];
    }

    public function isConfigured(): bool
    {
        return filled(config('social-hub.url')) && filled(config('social-hub.key'));
    }

    public function addonVersion(): string
    {
        try {
            if (InstalledVersions::isInstalled(self::PACKAGE)) {
                $version = (string) InstalledVersions::getPrettyVersion(self::PACKAGE);

                return $version === '' || str_contains($version, 'no-version-set') ? 'dev' : $version;
            }
        } catch (Throwable) {
            //
        }

        return 'dev';
    }

    public function statamicVersion(): string
    {
        try {
            $version = (string) Statamic::version();
        } catch (Throwable) {
            $version = '';
        }

        return $version !== '' ? $version : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $data = []): array
    {
        try {
            $response = $method === 'GET'
                ? $this->request()->get($path, $data)
                : $this->request()->send($method, $path, ['json' => $data]);
        } catch (ConnectionException $exception) {
            throw HubConnectionException::for($method, '/'.$path, $exception);
        }

        if ($response->failed()) {
            throw $this->requestException($method, $path, $response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    protected function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw HubNotConfiguredException::missing();
        }

        return Http::baseUrl(rtrim((string) config('social-hub.url'), '/'))
            ->timeout((int) config('social-hub.timeout', 5))
            ->connectTimeout(min(3, (int) config('social-hub.timeout', 5)))
            ->acceptJson()
            ->withToken((string) config('social-hub.key'))
            ->withHeaders([
                'X-Social-Hub-Addon' => $this->addonVersion(),
                'X-Statamic-Version' => $this->statamicVersion(),
            ]);
    }

    protected function requestException(string $method, string $path, Response $response): HubRequestException
    {
        $status = $response->status();
        $json = $response->json();
        $hubMessage = is_array($json) && is_string($json['message'] ?? null) ? mb_substr($json['message'], 0, 300) : null;
        $errors = is_array($json) && is_array($json['errors'] ?? null) ? $json['errors'] : [];

        $reason = match (true) {
            $status === 401 => 'API-Key ungültig oder abgelaufen',
            $status === 403 => 'kein Zugriff',
            $status === 404 => 'nicht gefunden',
            $status === 409 => 'nicht mehr änderbar',
            $status === 422 => 'ungültige Daten',
            $status === 429 => 'zu viele Anfragen',
            $status >= 500 => 'Fehler im Hub',
            default => 'unerwartete Antwort',
        };

        return new HubRequestException(
            "Social Hub antwortet mit {$status} ({$reason}) auf {$method} /{$path}.",
            $status,
            $errors,
            $hubMessage,
        );
    }
}
