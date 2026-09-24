<?php

namespace WursterMedien\SocialHub\Support;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Zugangsdaten zum Hub: Adresse, API-Key der Seite und Webhook-Secret.
 *
 * Quelle ist die .env (SOCIAL_HUB_URL, SOCIAL_HUB_KEY, SOCIAL_HUB_WEBHOOK_SECRET). Fehlt dort ein Wert,
 * gilt der Verbindungscode, der im Control Panel eingefügt wurde. Er liegt verschlüsselt (APP_KEY)
 * in storage/app/social-hub/connection.json, damit für die Einrichtung niemand die .env bearbeiten muss.
 *
 * Verbindungscode (erzeugt der Hub): "shc1." + base64url(JSON {u: Hub-Adresse, k: API-Key, s: Webhook-Secret}).
 * Fehlt k oder s im Code, bleibt der bisher gespeicherte Wert.
 */
class HubConnection
{
    public const CODE_PREFIX = 'shc1.';

    protected const FILE = 'connection';

    /** @var array{url?: string, key?: string, webhook_secret?: string}|null */
    protected ?array $stored = null;

    public function __construct(protected StateStore $store) {}

    public function url(): ?string
    {
        return $this->value('url');
    }

    public function key(): ?string
    {
        return $this->value('key');
    }

    public function webhookSecret(): ?string
    {
        return $this->value('webhook_secret');
    }

    public function isConfigured(): bool
    {
        return filled($this->url()) && filled($this->key());
    }

    /**
     * Stehen Adresse und Key in der .env? Dann hat ein eingefügter Code keine Wirkung.
     */
    public function usesEnvironment(): bool
    {
        return filled(config('social-hub.url')) || filled(config('social-hub.key'));
    }

    public function hasStoredCode(): bool
    {
        return $this->stored() !== [];
    }

    /**
     * Liest einen Verbindungscode und ergänzt ihn um die bisher gespeicherten Werte.
     *
     * @return array{url: string, key: string, webhook_secret: string|null}
     *
     * @throws InvalidConnectionCode
     */
    public function parse(string $code): array
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! str_starts_with($code, self::CODE_PREFIX)) {
            throw InvalidConnectionCode::format();
        }

        $json = base64_decode(strtr(substr($code, strlen(self::CODE_PREFIX)), '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! is_string($data['u'] ?? null)) {
            throw InvalidConnectionCode::format();
        }

        $url = rtrim($data['u'], '/');

        if (! self::isAllowedUrl($url)) {
            throw InvalidConnectionCode::insecureUrl();
        }

        $stored = $this->stored();
        $key = is_string($data['k'] ?? null) ? $data['k'] : ($stored['key'] ?? null);

        if (blank($key)) {
            throw InvalidConnectionCode::missingKey();
        }

        return [
            'url' => $url,
            'key' => $key,
            'webhook_secret' => is_string($data['s'] ?? null) ? $data['s'] : ($stored['webhook_secret'] ?? null),
        ];
    }

    /**
     * @param  array{url: string, key: string, webhook_secret: string|null}  $credentials
     */
    public function store(array $credentials): void
    {
        $this->store->put(self::FILE, [
            'payload' => Crypt::encryptString(json_encode(array_filter($credentials), JSON_THROW_ON_ERROR)),
            'stored_at' => now()->toIso8601String(),
        ]);

        $this->stored = null;
    }

    public function forget(): void
    {
        $this->store->delete(self::FILE);
        $this->stored = null;
    }

    /**
     * Nur https. Lokal (APP_ENV=local) zusätzlich http auf *.test.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return $scheme === 'https' || ($scheme === 'http' && app()->isLocal() && str_ends_with($host, '.test'));
    }

    protected function value(string $name): ?string
    {
        $configured = config('social-hub.'.$name);

        if (filled($configured)) {
            return (string) $configured;
        }

        return $this->stored()[$name] ?? null;
    }

    /**
     * @return array{url?: string, key?: string, webhook_secret?: string}
     */
    protected function stored(): array
    {
        if ($this->stored !== null) {
            return $this->stored;
        }

        $payload = $this->store->get(self::FILE)['payload'] ?? null;

        try {
            $data = is_string($payload) ? json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (Throwable) {
            // Anderer APP_KEY oder beschädigte Datei: wie nicht verbunden behandeln.
            $data = [];
        }

        return $this->stored = is_array($data) ? array_filter($data, 'is_string') : [];
    }
}
