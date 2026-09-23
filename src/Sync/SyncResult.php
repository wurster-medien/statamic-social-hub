<?php

namespace WursterMedien\SocialHub\Sync;

/**
 * Ergebnis eines Sync-Laufs (für Befehl und Control Panel).
 */
class SyncResult
{
    /**
     * @param  array<string, array{ok: bool, items: int, message: string|null}>  $feeds
     * @param  array{downloaded: int, existing: int, failed: int, skipped: int}  $media
     * @param  list<string>  $errors
     */
    public function __construct(
        public bool $pingOk = false,
        public ?string $siteName = null,
        public int $accounts = 0,
        public array $feeds = [],
        public array $media = ['downloaded' => 0, 'existing' => 0, 'failed' => 0, 'skipped' => 0],
        public int $pruned = 0,
        public array $errors = [],
    ) {}

    public function successful(): bool
    {
        return $this->pingOk && $this->errors === [];
    }

    public function summary(): string
    {
        $feedsOk = count(array_filter($this->feeds, fn (array $feed) => $feed['ok']));

        $text = sprintf(
            '%d von %d Feeds aktualisiert, %d Medien geladen, %d vorhanden, %d gelöscht.',
            $feedsOk,
            count($this->feeds),
            $this->media['downloaded'],
            $this->media['existing'],
            $this->pruned,
        );

        if ($this->errors !== []) {
            $text .= ' Fehler: '.implode(' | ', $this->errors);
        }

        return $text;
    }
}
