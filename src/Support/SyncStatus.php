<?php

namespace WursterMedien\SocialHub\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Zustand für die Control-Panel-Seite: letzter Sync, letzter Ping und die
 * letzten Fehler. Wird in status.json gespeichert.
 */
class SyncStatus
{
    public const MAX_ERRORS = 20;

    public function __construct(protected StateStore $store) {}

    /**
     * @return array{last_sync_at?: string|null, last_sync_ok?: bool, ping?: array<string, mixed>|null, accounts?: array<string, string>, errors?: list<array{time: string, context: string, message: string}>}
     */
    public function all(): array
    {
        return $this->store->get('status') ?? [];
    }

    public function recordError(string $context, string $message): void
    {
        Log::warning("[Social Hub] {$context}: {$message}");

        $status = $this->all();
        $errors = $status['errors'] ?? [];

        array_unshift($errors, [
            'time' => Carbon::now()->toIso8601String(),
            'context' => $context,
            'message' => mb_substr($message, 0, 500),
        ]);

        $status['errors'] = array_slice($errors, 0, self::MAX_ERRORS);

        $this->store->put('status', $status);
    }

    public function recordAccountSync(string $handle): void
    {
        $status = $this->all();
        $status['accounts'][$handle] = Carbon::now()->toIso8601String();

        $this->store->put('status', $status);
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function recordPing(?array $response, ?string $error = null): void
    {
        $status = $this->all();
        $status['ping'] = [
            'ok' => $error === null && ($response['ok'] ?? true) !== false,
            'checked_at' => Carbon::now()->toIso8601String(),
            'hub' => $response['hub'] ?? null,
            'site' => $response['site'] ?? null,
            'error' => $error,
        ];

        $this->store->put('status', $status);
    }

    public function recordSync(bool $successful): void
    {
        $status = $this->all();
        $status['last_sync_at'] = Carbon::now()->toIso8601String();
        $status['last_sync_ok'] = $successful;

        $this->store->put('status', $status);
    }

    public function clearErrors(): void
    {
        $status = $this->all();
        $status['errors'] = [];

        $this->store->put('status', $status);
    }
}
