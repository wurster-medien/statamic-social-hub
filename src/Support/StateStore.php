<?php

namespace WursterMedien\SocialHub\Support;

use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Dauerhafter Speicher des Addons als JSON-Dateien unter storage/app/social-hub.
 *
 * Hier liegen der letzte gute Feed je Konto (feeds/{handle}.json), die
 * Kontenliste (accounts.json) und der Sync-Status (status.json). Anders als der
 * Laravel-Cache überlebt das ein cache:clear und dient als Rückfall, wenn der
 * Hub nicht erreichbar ist.
 */
class StateStore
{
    public function __construct(protected Filesystem $files) {}

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $path = $this->path($name);

        if (! $this->files->exists($path)) {
            return null;
        }

        try {
            $data = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $name, array $data): void
    {
        $path = $this->path($name);

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->replace($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function delete(string $name): void
    {
        $this->files->delete($this->path($name));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function feed(string $handle): ?array
    {
        return $this->get('feeds/'.self::safeName($handle));
    }

    /**
     * @param  array<string, mixed>  $feed
     */
    public function putFeed(string $handle, array $feed): void
    {
        $this->put('feeds/'.self::safeName($handle), $feed);
    }

    public function deleteFeed(string $handle): void
    {
        $this->delete('feeds/'.self::safeName($handle));
    }

    /**
     * Alle gespeicherten Feeds, Schlüssel = Handle.
     *
     * @return array<string, array<string, mixed>>
     */
    public function feeds(): array
    {
        $directory = $this->root().'/feeds';

        if (! $this->files->isDirectory($directory)) {
            return [];
        }

        $feeds = [];

        foreach ($this->files->files($directory) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $name = $file->getBasename('.json');
            $feed = $this->get('feeds/'.$name);

            if ($feed !== null) {
                $feeds[(string) ($feed['handle'] ?? $name)] = $feed;
            }
        }

        return $feeds;
    }

    public function root(): string
    {
        return rtrim((string) config('social-hub.storage_path', storage_path('app/social-hub')), '/\\');
    }

    /**
     * Macht aus einem Handle einen sicheren Datei- bzw. Ordnernamen.
     */
    public static function safeName(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?? '';
        $safe = ltrim($safe, '.');

        return $safe === '' ? '_' : $safe;
    }

    protected function path(string $name): string
    {
        return $this->root().'/'.$name.'.json';
    }
}
