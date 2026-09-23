<?php

namespace WursterMedien\SocialHub\Accounts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;
use WursterMedien\SocialHub\Hub\HubClient;
use WursterMedien\SocialHub\Hub\HubException;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Support\SyncStatus;

/**
 * Konten, die der Hub dieser Seite freigibt (GET /api/v1/accounts).
 *
 * Wie beim Feed: Laravel-Cache, dazu die letzte gute Liste in accounts.json
 * als Rückfall. all() wirft nie eine Exception.
 */
class AccountRepository
{
    public const CACHE_KEY = 'social-hub:accounts';

    public function __construct(
        protected HubClient $client,
        protected StateStore $store,
        protected SyncStatus $status,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        if (! $this->client->isConfigured()) {
            return $this->stored();
        }

        try {
            return $this->refresh();
        } catch (Throwable $exception) {
            $this->status->recordError('Konten', $exception instanceof HubException ? $exception->getMessage() : 'Unerwarteter Fehler: '.class_basename($exception));

            $accounts = $this->stored();
            Cache::put(self::CACHE_KEY, $accounts, now()->addMinutes((int) config('social-hub.retry_minutes', 5)));

            return $accounts;
        }
    }

    /**
     * Holt die Konten neu vom Hub.
     *
     * @return list<array<string, mixed>>
     *
     * @throws HubException
     */
    public function refresh(): array
    {
        $accounts = array_values(array_filter($this->client->accounts(), fn ($account) => is_array($account) && filled($account['handle'] ?? null)));

        $this->store->put('accounts', [
            'fetched_at' => Carbon::now()->toIso8601String(),
            'data' => $accounts,
        ]);

        Cache::put(self::CACHE_KEY, $accounts, now()->addMinutes((int) config('social-hub.cache_minutes', 30)));

        return $accounts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $handle): ?array
    {
        foreach ($this->all() as $account) {
            if ((string) $account['handle'] === $handle) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function handles(): array
    {
        return array_values(array_map(fn (array $account) => (string) $account['handle'], $this->all()));
    }

    /**
     * Konto für Tags ohne account-Parameter: Config, sonst das erste Konto.
     */
    public function defaultHandle(): ?string
    {
        if (filled($default = config('social-hub.default_account'))) {
            return (string) $default;
        }

        return $this->handles()[0] ?? null;
    }

    /**
     * Zuletzt gespeicherte Kontenliste (ohne Hub-Aufruf).
     *
     * @return list<array<string, mixed>>
     */
    public function stored(): array
    {
        return array_values($this->store->get('accounts')['data'] ?? []);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
