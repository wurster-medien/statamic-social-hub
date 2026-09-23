<?php

namespace WursterMedien\SocialHub\Feeds;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use WursterMedien\SocialHub\Hub\HubClient;
use WursterMedien\SocialHub\Hub\HubException;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Support\SyncStatus;

/**
 * Liefert den Feed eines Kontos.
 *
 * Reihenfolge: Laravel-Cache → Hub (mit Spiegeln der Medien) → letzter guter
 * Feed aus storage/app/social-hub/feeds/{handle}.json (höchstens stale_days
 * alt) → leere Liste. items() und feed() werfen nie eine Exception, damit ein
 * Hub-Ausfall nie eine Seite kaputt macht.
 */
class FeedRepository
{
    public function __construct(
        protected HubClient $client,
        protected MediaMirror $mirror,
        protected StateStore $store,
        protected SyncStatus $status,
    ) {}

    /**
     * Medien eines Kontos, bereits auf limit gekürzt.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $handle, ?int $limit = null, int $offset = 0): array
    {
        $items = $this->feed($handle)['data'];

        return array_values(array_slice($items, max(0, $offset), $limit === null ? null : max(0, $limit)));
    }

    /**
     * @return array{handle: string, data: list<array<string, mixed>>, meta: array<string, mixed>, fetched_at: string|null, stale: bool}
     */
    public function feed(string $handle): array
    {
        $cached = Cache::get($this->cacheKey($handle));

        if (is_array($cached)) {
            return $cached;
        }

        if (! $this->client->isConfigured()) {
            return $this->fallback($handle, null);
        }

        $lock = $this->lock($handle);

        if ($lock !== null && ! $this->acquire($lock)) {
            // Ein anderer Aufruf lädt den Feed gerade. Nicht warten, sondern den
            // letzten guten Stand ausliefern.
            return $this->stored($handle) ?? $this->emptyFeed($handle);
        }

        try {
            $deadline = microtime(true) + (float) config('social-hub.mirror_budget_seconds', 8);

            return $this->refresh($handle, $deadline);
        } catch (Throwable $exception) {
            return $this->fallback($handle, $exception);
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Ein einzelnes Medium: zuerst im Feed, sonst direkt beim Hub.
     *
     * @return array<string, mixed>|null
     */
    public function media(string $handle, string $id): ?array
    {
        foreach ($this->feed($handle)['data'] as $item) {
            if ((string) ($item['id'] ?? '') === $id) {
                return $item;
            }

            foreach ($item['children'] ?? [] as $child) {
                if (is_array($child) && (string) ($child['id'] ?? '') === $id) {
                    return $child;
                }
            }
        }

        if (! $this->client->isConfigured()) {
            return null;
        }

        $key = $this->cacheKey($handle).':media:'.sha1($id);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached['item'];
        }

        try {
            $item = $this->client->media($handle, $id);
            $deadline = microtime(true) + (float) config('social-hub.mirror_budget_seconds', 8);
            $item = is_array($item) ? $this->mirror->mirrorItem($handle, $item, $deadline) : null;
            Cache::put($key, ['item' => $item], now()->addMinutes((int) config('social-hub.cache_minutes', 30)));

            return $item;
        } catch (Throwable $exception) {
            $this->status->recordError("Medium {$handle}/{$id}", $this->describe($exception));
            Cache::put($key, ['item' => null], now()->addMinutes((int) config('social-hub.retry_minutes', 5)));

            return null;
        }
    }

    /**
     * Holt den Feed neu vom Hub, spiegelt die Medien und speichert ihn.
     *
     * @param  float|null  $deadline  Zeitbudget für Downloads (null = unbegrenzt)
     * @return array{handle: string, data: list<array<string, mixed>>, meta: array<string, mixed>, fetched_at: string|null, stale: bool}
     *
     * @throws HubException
     */
    public function refresh(string $handle, ?float $deadline = null): array
    {
        $limit = max((int) config('social-hub.fetch_limit', 50), (int) config('social-hub.default_limit', 12));
        $response = $this->client->feed($handle, $limit);

        $items = array_values(array_filter($response['data'], 'is_array'));

        $feed = [
            'handle' => $handle,
            'data' => $this->mirror->mirrorFeed($handle, $items, $deadline),
            'meta' => $response['meta'],
            'fetched_at' => Carbon::now()->toIso8601String(),
            'stale' => false,
        ];

        $this->store->putFeed($handle, $feed);
        $this->status->recordAccountSync($handle);

        Cache::put($this->cacheKey($handle), $feed, now()->addMinutes((int) config('social-hub.cache_minutes', 30)));

        return $feed;
    }

    /**
     * Letzter guter Feed, solange er nicht älter als stale_days ist.
     *
     * @return array{handle: string, data: list<array<string, mixed>>, meta: array<string, mixed>, fetched_at: string|null, stale: bool}|null
     */
    public function stored(string $handle): ?array
    {
        $feed = $this->store->feed($handle);

        if ($feed === null || ! $this->isFresh($feed)) {
            return null;
        }

        return [
            'handle' => $handle,
            'data' => array_values($feed['data'] ?? []),
            'meta' => $feed['meta'] ?? [],
            'fetched_at' => $feed['fetched_at'] ?? null,
            'stale' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $feed
     */
    public function isFresh(array $feed): bool
    {
        try {
            $fetchedAt = Carbon::parse((string) ($feed['fetched_at'] ?? ''));
        } catch (Throwable) {
            return false;
        }

        return $fetchedAt->greaterThan(Carbon::now()->subDays((int) config('social-hub.stale_days', 7)));
    }

    public function forget(string $handle): void
    {
        Cache::forget($this->cacheKey($handle));
    }

    public function cacheKey(string $handle): string
    {
        return 'social-hub:feed:'.$handle;
    }

    /**
     * @return array{handle: string, data: list<array<string, mixed>>, meta: array<string, mixed>, fetched_at: string|null, stale: bool}
     */
    protected function fallback(string $handle, ?Throwable $exception): array
    {
        if ($exception !== null) {
            $this->status->recordError("Feed {$handle}", $this->describe($exception));
        }

        $feed = $this->stored($handle);

        if ($feed === null) {
            if ($exception !== null) {
                Log::warning("[Social Hub] Kein gültiger Feed für \"{$handle}\" vorhanden, es wird eine leere Liste ausgeliefert.");
            }

            $feed = $this->emptyFeed($handle);
        }

        // Kurz zwischenspeichern, damit nicht jeder Seitenaufruf auf den Hub wartet.
        Cache::put($this->cacheKey($handle), $feed, now()->addMinutes((int) config('social-hub.retry_minutes', 5)));

        return $feed;
    }

    /**
     * @return array{handle: string, data: list<array<string, mixed>>, meta: array<string, mixed>, fetched_at: string|null, stale: bool}
     */
    protected function emptyFeed(string $handle): array
    {
        return ['handle' => $handle, 'data' => [], 'meta' => [], 'fetched_at' => null, 'stale' => true];
    }

    protected function describe(Throwable $exception): string
    {
        return $exception instanceof HubException
            ? $exception->getMessage()
            : 'Unerwarteter Fehler: '.class_basename($exception);
    }

    protected function acquire(Lock $lock): bool
    {
        try {
            return (bool) $lock->get();
        } catch (Throwable) {
            // Cache-Store ohne funktionierende Sperren: einfach weitermachen.
            return true;
        }
    }

    protected function release(?Lock $lock): void
    {
        try {
            $lock?->release();
        } catch (Throwable) {
            //
        }
    }

    protected function lock(string $handle): ?Lock
    {
        try {
            $store = Cache::getStore();

            return $store instanceof LockProvider ? $store->lock('social-hub:refresh:'.$handle, 60) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
