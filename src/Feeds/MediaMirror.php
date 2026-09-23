<?php

namespace WursterMedien\SocialHub\Feeds;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use WursterMedien\SocialHub\Support\StateStore;

/**
 * Spiegelt die Medien eines Feeds vom Hub nach public/{media_path}/{handle}/.
 *
 * Danach zeigen media_url und thumbnail_url auf lokale Pfade wie
 * /social-hub/rath_bau/1789.jpg, die der Browser direkt lädt und die Glide
 * (src="/…" wird unter public/ gesucht) lokal verarbeiten kann.
 *
 * Bereits vorhandene Dateien werden nicht erneut geladen. Schlägt ein Download
 * fehl oder ist das Zeitbudget erschöpft, bleibt die Hub-URL stehen, damit das
 * Bild trotzdem angezeigt wird.
 */
class MediaMirror
{
    public const DISK = 'social-hub';

    /**
     * @var array{downloaded: int, existing: int, failed: int, skipped: int}
     */
    protected array $stats = ['downloaded' => 0, 'existing' => 0, 'failed' => 0, 'skipped' => 0];

    /**
     * @var list<string>
     */
    protected array $failures = [];

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  float|null  $deadline  microtime(true), bis zu der geladen werden darf
     * @return list<array<string, mixed>>
     */
    public function mirrorFeed(string $handle, array $items, ?float $deadline = null): array
    {
        return array_map(fn (array $item) => $this->mirrorItem($handle, $item, $deadline), $items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function mirrorItem(string $handle, array $item, ?float $deadline = null): array
    {
        $id = $this->safeId($item['id'] ?? null);

        if ($id === null) {
            return $item;
        }

        $folder = StateStore::safeName($handle);

        if (is_string($item['media_url'] ?? null)) {
            $item['media_url'] = $this->mirror($item['media_url'], "{$folder}/{$id}", $deadline);
        }

        if (is_string($item['thumbnail_url'] ?? null)) {
            $item['thumbnail_url'] = $this->mirror($item['thumbnail_url'], "{$folder}/{$id}_thumb", $deadline);
        }

        if (is_array($item['children'] ?? null)) {
            $item['children'] = array_values(array_map(
                fn ($child) => is_array($child) ? $this->mirrorItem($handle, $child, $deadline) : $child,
                $item['children'],
            ));
        }

        return $item;
    }

    /**
     * Lädt eine Datei, wenn sie noch nicht lokal liegt, und gibt die lokale URL
     * zurück. Bei Fehlern bleibt die ursprüngliche URL erhalten.
     */
    public function mirror(string $url, string $basePath, ?float $deadline = null): string
    {
        if ($this->isLocal($url) || ! Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $path = $basePath.'.'.$this->extensionFromUrl($url);

        if ($this->disk()->exists($path)) {
            $this->stats['existing']++;

            return $this->localUrl($path);
        }

        if ($deadline !== null && microtime(true) >= $deadline) {
            $this->stats['skipped']++;

            return $url;
        }

        try {
            $response = Http::timeout($this->downloadTimeout($deadline))
                ->connectTimeout(3)
                ->withHeaders(['Accept' => 'image/*,video/*'])
                ->get($url);

            $body = $response->body();

            if ($response->failed() || $body === '') {
                throw new \RuntimeException('Status '.$response->status());
            }

            if (strlen($body) > $this->maxBytes()) {
                throw new \RuntimeException('Datei zu groß');
            }

            $this->disk()->put($path, $body);
        } catch (Throwable $exception) {
            $this->stats['failed']++;
            $this->failures[] = basename($path).': '.Str::limit($exception->getMessage(), 120);

            return $url;
        }

        $this->stats['downloaded']++;

        return $this->localUrl($path);
    }

    /**
     * Alle lokalen Dateipfade (relativ zur Disk), auf die die Medien verweisen.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    public function referencedPaths(array $items): array
    {
        $paths = [];

        foreach ($items as $item) {
            foreach (['media_url', 'thumbnail_url'] as $key) {
                if (is_string($item[$key] ?? null) && $this->isLocal($item[$key])) {
                    $paths[] = $this->pathFromLocalUrl($item[$key]);
                }
            }

            if (is_array($item['children'] ?? null)) {
                array_push($paths, ...$this->referencedPaths(array_values(array_filter($item['children'], 'is_array'))));
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Löscht alle gespiegelten Dateien, die in keinem Feed mehr vorkommen.
     *
     * @param  list<string>  $keep  Pfade relativ zur Disk
     * @return int Anzahl gelöschter Dateien
     */
    public function prune(array $keep): int
    {
        $keep = array_flip($keep);
        $deleted = 0;

        foreach ($this->disk()->allFiles() as $file) {
            $file = str_replace('\\', '/', $file);

            if (isset($keep[$file]) || Str::startsWith(basename($file), '.')) {
                continue;
            }

            $this->disk()->delete($file);
            $deleted++;
        }

        foreach (array_reverse($this->disk()->allDirectories()) as $directory) {
            if ($this->disk()->allFiles($directory) === []) {
                $this->disk()->deleteDirectory($directory);
            }
        }

        return $deleted;
    }

    public function isLocal(string $url): bool
    {
        return Str::startsWith($url, $this->urlPrefix().'/');
    }

    public function localUrl(string $path): string
    {
        return $this->urlPrefix().'/'.ltrim($path, '/');
    }

    public function pathFromLocalUrl(string $url): string
    {
        return ltrim(Str::after($url, $this->urlPrefix().'/'), '/');
    }

    /**
     * @return array{downloaded: int, existing: int, failed: int, skipped: int}
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function resetStats(): void
    {
        $this->stats = ['downloaded' => 0, 'existing' => 0, 'failed' => 0, 'skipped' => 0];
        $this->failures = [];
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Konfiguration der Disk, die der ServiceProvider registriert.
     *
     * @return array<string, mixed>
     */
    public static function diskConfig(): array
    {
        $mediaPath = trim((string) config('social-hub.media_path', 'social-hub'), '/');

        return [
            'driver' => 'local',
            'root' => public_path($mediaPath),
            'url' => '/'.$mediaPath,
            'visibility' => 'public',
            'throw' => false,
        ];
    }

    protected function urlPrefix(): string
    {
        return '/'.trim((string) config('social-hub.media_path', 'social-hub'), '/');
    }

    protected function extensionFromUrl(string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'mov', 'webm', 'm4v'], true)
            ? $extension
            : 'jpg';
    }

    protected function safeId(mixed $id): ?string
    {
        if (! is_scalar($id)) {
            return null;
        }

        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $id) ?? '';

        return $safe === '' ? null : $safe;
    }

    protected function downloadTimeout(?float $deadline): int
    {
        $timeout = max(10, (int) config('social-hub.timeout', 5) * 4);

        if ($deadline === null) {
            return $timeout;
        }

        return max(1, min($timeout, (int) ceil($deadline - microtime(true))));
    }

    protected function maxBytes(): int
    {
        return (int) config('social-hub.max_download_mb', 25) * 1024 * 1024;
    }
}
