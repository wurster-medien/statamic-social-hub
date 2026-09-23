<?php

namespace WursterMedien\SocialHub\Sync;

use Throwable;
use WursterMedien\SocialHub\Accounts\AccountRepository;
use WursterMedien\SocialHub\Feeds\FeedRepository;
use WursterMedien\SocialHub\Feeds\MediaMirror;
use WursterMedien\SocialHub\Hub\HubClient;
use WursterMedien\SocialHub\Hub\HubException;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Support\SyncStatus;

/**
 * Kompletter Abgleich mit dem Hub: Ping, Konten, Feeds aller Konten, Medien
 * spiegeln und nicht mehr benötigte Medien löschen.
 *
 * Wird vom Befehl social-hub:sync (Scheduler) und vom Knopf im Control Panel
 * genutzt.
 */
class Synchronizer
{
    public function __construct(
        protected HubClient $client,
        protected AccountRepository $accounts,
        protected FeedRepository $feeds,
        protected MediaMirror $mirror,
        protected StateStore $store,
        protected SyncStatus $status,
    ) {}

    /**
     * @param  list<string>  $only  nur diese Handles abgleichen (dann ohne Aufräumen)
     * @param  float|null  $deadline  Zeitbudget für Downloads (microtime)
     */
    public function run(array $only = [], ?float $deadline = null): SyncResult
    {
        $result = new SyncResult;
        $this->mirror->resetStats();

        if (! $this->client->isConfigured()) {
            $result->errors[] = 'Social Hub ist nicht konfiguriert (SOCIAL_HUB_URL und SOCIAL_HUB_KEY).';
            $this->status->recordSync(false);

            return $result;
        }

        $this->ping($result);

        try {
            $handles = $this->handlesOf($this->accounts->refresh());
        } catch (HubException $exception) {
            $this->status->recordError('Konten', $exception->getMessage());
            $result->errors[] = $exception->getMessage();
            $handles = $this->handlesOf($this->accounts->stored());
        }

        $result->accounts = count($handles);
        $selected = $only === [] ? $handles : $only;

        foreach ($selected as $handle) {
            try {
                $feed = $this->feeds->refresh($handle, $deadline);
                $result->feeds[$handle] = ['ok' => true, 'items' => count($feed['data']), 'message' => null];
            } catch (Throwable $exception) {
                $message = $exception instanceof HubException ? $exception->getMessage() : 'Unerwarteter Fehler: '.class_basename($exception);
                $this->status->recordError("Feed {$handle}", $message);
                $result->feeds[$handle] = ['ok' => false, 'items' => 0, 'message' => $message];
                $result->errors[] = "{$handle}: {$message}";
            }
        }

        foreach ($this->mirror->failures() as $failure) {
            $this->status->recordError('Medien', $failure);
        }

        if ($only === []) {
            $result->pruned = $this->prune($handles);
        }

        $result->media = $this->mirror->stats();
        $this->status->recordSync($result->successful());

        return $result;
    }

    /**
     * Löscht gespeicherte Feeds von Konten, die der Hub nicht mehr liefert
     * (sobald sie älter als stale_days sind), und alle gespiegelten Medien, auf
     * die kein gespeicherter Feed mehr verweist.
     *
     * @param  list<string>  $activeHandles
     */
    public function prune(array $activeHandles): int
    {
        $keep = [];

        foreach ($this->store->feeds() as $handle => $feed) {
            if (! in_array((string) $handle, $activeHandles, true) && ! $this->feeds->isFresh($feed)) {
                $this->store->deleteFeed((string) $handle);

                continue;
            }

            array_push($keep, ...$this->mirror->referencedPaths(array_values($feed['data'] ?? [])));
        }

        try {
            return $this->mirror->prune(array_values(array_unique($keep)));
        } catch (Throwable $exception) {
            $this->status->recordError('Aufräumen', 'Medien konnten nicht aufgeräumt werden: '.class_basename($exception));

            return 0;
        }
    }

    protected function ping(SyncResult $result): void
    {
        try {
            $response = $this->client->ping();
            $this->status->recordPing($response);
            $result->pingOk = ($response['ok'] ?? true) !== false;
            $result->siteName = $response['site']['name'] ?? null;
        } catch (HubException $exception) {
            $this->status->recordPing(null, $exception->getMessage());
            $this->status->recordError('Ping', $exception->getMessage());
            $result->errors[] = $exception->getMessage();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return list<string>
     */
    protected function handlesOf(array $accounts): array
    {
        return array_values(array_map(fn (array $account) => (string) $account['handle'], $accounts));
    }
}
