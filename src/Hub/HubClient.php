<?php

namespace WursterMedien\SocialHub\Hub;

use Composer\InstalledVersions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Statamic\Statamic;
use Throwable;
use WursterMedien\SocialHub\Support\HubConnection;

/**
 * Einziger Ort mit HTTP-Aufrufen an die API v1 des Social Hubs.
 *
 * Authentifizierung per Bearer-Key der Seite (Zugangsdaten aus HubConnection). Zusätzlich
 * werden die Versionen von Addon und Statamic mitgeschickt, damit der Hub veraltete Seiten erkennt.
 */
class HubClient
{
    public const PACKAGE = 'wurster-medien/statamic-social-hub';

    /** @var array{url: string, key: string}|null */
    protected ?array $credentials = null;

    public function __construct(protected HubConnection $connection) {}

    /**
     * Eine Kopie mit anderen Zugangsdaten, z. B. um einen eingefügten Verbindungscode vor dem Speichern zu prüfen.
     */
    public function withCredentials(string $url, string $key): static
    {
        $client = clone $this;
        $client->credentials = ['url' => $url, 'key' => $key];

        return $client;
    }

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
     * Der Hub lädt dabei die Medien synchron, deshalb gilt hier das eigene
     * Timeout post_timeout (Standard 120 s) statt des kurzen Feed-Timeouts.
     * Es gibt bewusst keinen automatischen zweiten Versuch: Nach einem Timeout
     * kann der Post im Hub bereits angelegt sein.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws HubTimeoutException wenn der Hub nicht rechtzeitig antwortet
     */
    public function createPost(array $payload): array
    {
        $timeout = max(1, (int) config('social-hub.post_timeout', 120));
        $this->extendTimeLimit($timeout);

        try {
            return $this->send('POST', 'api/v1/posts', $payload, $timeout)['data'] ?? [];
        } catch (HubConnectionException $exception) {
            throw $exception->isResponseTimeout()
                ? HubTimeoutException::postMayExist($exception->getPrevious())
                : $exception;
        }
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
        return $this->credentials !== null || $this->connection->isConfigured();
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
    protected function send(string $method, string $path, array $data = [], ?int $timeout = null): array
    {
        try {
            $response = $method === 'GET'
                ? $this->request($timeout)->get($path, $data)
                : $this->request($timeout)->send($method, $path, ['json' => $data]);
        } catch (ConnectionException $exception) {
            throw HubConnectionException::for($method, '/'.$path, $exception);
        }

        if ($response->failed()) {
            throw $this->requestException($method, $path, $response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  int|null  $timeout  Sekunden, null = social-hub.timeout
     */
    protected function request(?int $timeout = null): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw HubNotConfiguredException::missing();
        }

        return Http::baseUrl(rtrim((string) ($this->credentials['url'] ?? $this->connection->url()), '/'))
            ->timeout($timeout ?? (int) config('social-hub.timeout', 5))
            ->connectTimeout(min(3, (int) config('social-hub.timeout', 5)))
            ->acceptJson()
            ->withToken((string) ($this->credentials['key'] ?? $this->connection->key()))
            ->withHeaders([
                'X-Social-Hub-Addon' => $this->addonVersion(),
                'X-Statamic-Version' => $this->statamicVersion(),
            ]);
    }

    /**
     * Verhindert, dass PHP (max_execution_time) den Aufruf vor dem HTTP-Timeout
     * beendet. Ohne Limit (z. B. auf der Konsole) bleibt es dabei.
     */
    protected function extendTimeLimit(int $timeout): void
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0 && $limit < $timeout + 30 && function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }
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
