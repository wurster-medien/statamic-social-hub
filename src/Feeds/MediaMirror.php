<?php

namespace WursterMedien\SocialHub\Feeds;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use WursterMedien\SocialHub\Support\HubConnection;
use WursterMedien\SocialHub\Support\StateStore;

/**
 * Spiegelt die Medien eines Feeds vom Hub nach public/{media_path}/{handle}/.
 *
 * Danach zeigen media_url und thumbnail_url auf lokale Pfade wie
 * /social-hub/muster_bau/1789.jpg, die der Browser direkt lädt und die Glide
 * (src="/…" wird unter public/ gesucht) lokal verarbeiten kann.
 *
 * Bereits vorhandene Dateien werden nicht erneut geladen. Schlägt ein Download
 * fehl oder ist das Zeitbudget erschöpft, bleibt die Hub-URL stehen, damit das
 * Bild trotzdem angezeigt wird.
 *
 * Downloads laufen per Stream in eine temporäre Datei (nie komplett in den
 * Speicher) und brechen ab, sobald max_download_mb überschritten ist.
 *
 * Geladen wird nur vom Host des Hubs (und von den Hosts in media_hosts). Eine
 * Medien-URL auf einen anderen Host bleibt unverändert stehen, damit die Seite
 * keine beliebigen Adressen abruft, falls ein Feed manipuliert wurde.
 *
 * Schutz fremder Dateien: Ist media_path leer, "/" oder enthält "..", wird
 * weder gespiegelt noch aufgeräumt. prune() löscht nur Dateien nach dem eigenen
 * Namensschema {handle}/{id}(_thumb).{ext}.
 *
 * Beim ersten Download legt es public/{media_path}/.gitignore an, damit die
 * Medien nicht im Git-Repo der Seite landen.
 */
class MediaMirror
{
    public const DISK = 'social-hub';

    public const DEFAULT_MEDIA_PATH = 'social-hub';

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic'];

    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm', 'm4v'];

    /**
     * Eigenes Namensschema: {handle}/{id}(_thumb).{ext}
     */
    protected const FILE_PATTERN = '#^[A-Za-z0-9_-][A-Za-z0-9._-]*/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|gif|webp|avif|heic|mp4|mov|webm|m4v)$#';

    protected const FOLDER_PATTERN = '#^[A-Za-z0-9_-][A-Za-z0-9._-]*$#';

    protected const GITIGNORE = "# Vom Social Hub gespiegelte Medien. Werden automatisch geladen, nicht versionieren.\n*\n";

    /**
     * @var array{downloaded: int, existing: int, failed: int, skipped: int}
     */
    protected array $stats = ['downloaded' => 0, 'existing' => 0, 'failed' => 0, 'skipped' => 0];

    /**
     * @var list<string>
     */
    protected array $failures = [];

    protected bool $warnedAboutConfiguration = false;

    protected bool $gitIgnoreChecked = false;

    public function __construct(protected HubConnection $connection) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  float|null  $deadline  microtime(true), bis zu der geladen werden darf
     * @param  bool  $includeVideos  false = Videos nicht laden (Seitenaufruf), die Hub-URL bleibt
     * @return list<array<string, mixed>>
     */
    public function mirrorFeed(string $handle, array $items, ?float $deadline = null, bool $includeVideos = true): array
    {
        return array_map(fn (array $item) => $this->mirrorItem($handle, $item, $deadline, $includeVideos), $items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function mirrorItem(string $handle, array $item, ?float $deadline = null, bool $includeVideos = true): array
    {
        $id = $this->safeId($item['id'] ?? null);

        if ($id === null) {
            return $item;
        }

        $folder = StateStore::safeName($handle);

        // width/height beschreiben das Bild bzw. bei Videos das Vorschaubild.
        $width = is_numeric($item['width'] ?? null) ? (int) $item['width'] : null;

        if (is_string($item['media_url'] ?? null)) {
            $isVideo = $this->isVideo($item, $item['media_url']);
            $download = $includeVideos || ! $isVideo;
            $item['media_url'] = $this->mirror($item['media_url'], "{$folder}/{$id}", $deadline, $download, $isVideo ? null : $width);
        }

        if (is_string($item['thumbnail_url'] ?? null)) {
            $item['thumbnail_url'] = $this->mirror($item['thumbnail_url'], "{$folder}/{$id}_thumb", $deadline, true, $width);
        }

        if (is_array($item['children'] ?? null)) {
            $item['children'] = array_values(array_map(
                fn ($child) => is_array($child) ? $this->mirrorItem($handle, $child, $deadline, $includeVideos) : $child,
                $item['children'],
            ));
        }

        return $item;
    }

    /**
     * Lädt eine Datei, wenn sie noch nicht lokal liegt, und gibt die lokale URL
     * zurück. Bei Fehlern bleibt die ursprüngliche URL erhalten.
     *
     * Ist die lokale Datei ein Bild und schmaler als $expectedWidth (der Hub hat ein größeres, z. B. ein besseres
     * Vorschaubild), wird sie neu geladen. Scheitert das, bleibt die lokale Datei.
     *
     * @param  bool  $download  false = nur eine bereits vorhandene Datei verwenden
     * @param  int|null  $expectedWidth  Breite laut Hub, nur für Bilder
     */
    public function mirror(string $url, string $basePath, ?float $deadline = null, bool $download = true, ?int $expectedWidth = null): string
    {
        if (! $this->isEnabled() || $this->isLocal($url) || ! Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        if (! $this->isAllowedSource($url)) {
            $this->stats['skipped']++;
            $this->failures[] = Str::limit('Nicht vom Hub, nicht geladen: '.parse_url($url, PHP_URL_HOST), 120);

            return $url;
        }

        $path = $basePath.'.'.$this->extensionFromUrl($url);
        $exists = $this->disk()->exists($path);

        if ($exists && ! $this->isSmallerThan($path, $expectedWidth)) {
            $this->stats['existing']++;

            return $this->localUrl($path);
        }

        if (! $download || ($deadline !== null && microtime(true) >= $deadline)) {
            $this->stats[$exists ? 'existing' : 'skipped']++;

            return $exists ? $this->localUrl($path) : $url;
        }

        try {
            $this->download($url, $path, $deadline);
        } catch (Throwable $exception) {
            $this->stats['failed']++;
            $this->failures[] = basename($path).': '.Str::limit($this->describeFailure($exception), 120);

            return $exists ? $this->localUrl($path) : $url;
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
     * Löscht gespiegelte Dateien, die in keinem Feed mehr vorkommen.
     *
     * Berücksichtigt werden nur Dateien nach dem eigenen Namensschema
     * {handle}/{id}(_thumb).{ext} direkt in einem Konto-Ordner. Alles andere
     * auf der Disk bleibt unangetastet.
     *
     * @param  list<string>  $keep  Pfade relativ zur Disk
     * @return int Anzahl gelöschter Dateien
     */
    public function prune(array $keep): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $keep = array_flip($keep);
        $deleted = 0;

        foreach ($this->disk()->directories() as $directory) {
            $directory = trim(str_replace('\\', '/', $directory), '/');

            if (preg_match(self::FOLDER_PATTERN, $directory) !== 1) {
                continue;
            }

            foreach ($this->disk()->files($directory) as $file) {
                $file = str_replace('\\', '/', $file);

                if (isset($keep[$file]) || preg_match(self::FILE_PATTERN, $file) !== 1) {
                    continue;
                }

                $this->disk()->delete($file);
                $deleted++;
            }

            if ($this->disk()->allFiles($directory) === [] && $this->disk()->allDirectories($directory) === []) {
                $this->disk()->deleteDirectory($directory);
            }
        }

        return $deleted;
    }

    /**
     * Spiegeln und Aufräumen sind nur mit einem sicheren media_path aktiv.
     * Sonst einmal pro Prozess eine Warnung ins Log.
     */
    public function isEnabled(): bool
    {
        $problem = self::configurationProblem();

        if ($problem === null) {
            return true;
        }

        if (! $this->warnedAboutConfiguration) {
            $this->warnedAboutConfiguration = true;
            Log::warning("[Social Hub] Medien werden weder gespiegelt noch aufgeräumt: {$problem}");
        }

        return false;
    }

    /**
     * Grund, warum Spiegeln und Aufräumen deaktiviert sind, sonst null.
     */
    public static function configurationProblem(): ?string
    {
        if (self::mediaPath() === null) {
            return 'social-hub.media_path muss ein eigener Unterordner sein (nicht leer, nicht "/", ohne "..").';
        }

        $disk = config('filesystems.disks.'.self::DISK);
        $root = is_array($disk) && ($disk['driver'] ?? null) === 'local' ? ($disk['root'] ?? null) : null;

        if (is_string($root) && self::isSharedDirectory($root)) {
            return 'Die Disk "'.self::DISK.'" zeigt auf einen allgemeinen Ordner statt auf einen eigenen Unterordner.';
        }

        return null;
    }

    /**
     * Normalisierter media_path oder null, wenn er leer oder unsicher ist.
     */
    public static function mediaPath(): ?string
    {
        $value = config('social-hub.media_path', self::DEFAULT_MEDIA_PATH);

        if (! is_string($value)) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $value), '/');

        if ($path === '' || preg_match('#^[A-Za-z0-9._/-]+$#', $path) !== 1) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /**
     * Stammt die URL vom Hub selbst oder von einem Host aus media_hosts?
     */
    public function isAllowedSource(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        $allowed = [parse_url((string) $this->connection->url(), PHP_URL_HOST), ...(array) config('social-hub.media_hosts', [])];
        $allowed = array_map(strtolower(...), array_filter($allowed, is_string(...)));

        return in_array($host, $allowed, true);
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

    /**
     * Nur für lokale Disks prüfbar. Keine Angabe oder kein lesbares Bild: nicht kleiner.
     */
    protected function isSmallerThan(string $path, ?int $expectedWidth): bool
    {
        if ($expectedWidth === null || $expectedWidth <= 0) {
            return false;
        }

        try {
            $size = @getimagesize($this->disk()->path($path));
        } catch (Throwable) {
            return false;
        }

        return is_array($size) && $size[0] > 0 && $size[0] < $expectedWidth;
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
        $mediaPath = self::mediaPath() ?? self::DEFAULT_MEDIA_PATH;

        return [
            'driver' => 'local',
            'root' => public_path($mediaPath),
            'url' => '/'.$mediaPath,
            'visibility' => 'public',
            'throw' => false,
        ];
    }

    /**
     * Bricht ab, wenn der Server eine zu große Datei ankündigt (Content-Length).
     */
    public function guardContentLength(mixed $contentLength, int $maxBytes): void
    {
        if (is_array($contentLength)) {
            $contentLength = $contentLength[0] ?? null;
        }

        if (is_numeric($contentLength) && (int) $contentLength > $maxBytes) {
            throw new MediaTooLargeException('Datei zu groß ('.$this->megabytes((int) $contentLength).' MB, erlaubt '.$this->megabytes($maxBytes).' MB)');
        }
    }

    /**
     * Bricht während des Downloads ab, sobald mehr als erlaubt angekommen ist.
     */
    public function guardDownloaded(int $downloaded, int $maxBytes): void
    {
        if ($downloaded > $maxBytes) {
            throw new MediaTooLargeException('Datei zu groß (mehr als '.$this->megabytes($maxBytes).' MB)');
        }
    }

    public function maxBytes(): int
    {
        return max(1, (int) round((float) config('social-hub.max_download_mb', 25) * 1024 * 1024));
    }

    /**
     * Lädt per Stream in eine temporäre Datei und legt sie danach auf die Disk.
     */
    protected function download(string $url, string $path, ?float $deadline): void
    {
        $maxBytes = $this->maxBytes();
        $temporary = tempnam(sys_get_temp_dir(), 'social-hub-');

        if ($temporary === false) {
            throw new RuntimeException('Keine temporäre Datei möglich');
        }

        try {
            $response = Http::timeout($this->downloadTimeout($deadline))
                ->connectTimeout(3)
                ->withHeaders(['Accept' => 'image/*,video/*'])
                ->withOptions($this->streamOptions($maxBytes))
                ->sink($temporary)
                ->get($url);

            if ($response->failed()) {
                throw new RuntimeException('Status '.$response->status());
            }

            // Falls der HTTP-Handler on_headers nicht ausgewertet hat.
            $this->guardContentLength($response->header('Content-Length'), $maxBytes);

            clearstatcache(true, $temporary);
            $size = (int) filesize($temporary);

            if ($size === 0) {
                throw new RuntimeException('Leere Antwort');
            }

            $this->guardDownloaded($size, $maxBytes);
            $this->store($temporary, $path);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Guzzle-Optionen: Größe aus dem Header prüfen, während des Streams
     * mitzählen und bei cURL die Maximalgröße zusätzlich nativ begrenzen.
     *
     * @return array<string, mixed>
     */
    protected function streamOptions(int $maxBytes): array
    {
        $options = [
            'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                $this->guardContentLength($response->getHeaderLine('Content-Length'), $maxBytes);
            },
            'progress' => function ($downloadTotal, $downloaded) use ($maxBytes): void {
                $this->guardDownloaded((int) $downloaded, $maxBytes);
            },
        ];

        if (defined('CURLOPT_MAXFILESIZE_LARGE')) {
            $options['curl'] = [CURLOPT_MAXFILESIZE_LARGE => $maxBytes];
        }

        return $options;
    }

    protected function store(string $temporary, string $path): void
    {
        $this->ensureGitIgnore();

        $stream = fopen($temporary, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Temporäre Datei nicht lesbar');
        }

        try {
            if ($this->disk()->writeStream($path, $stream) === false) {
                throw new RuntimeException('Datei konnte nicht gespeichert werden');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Legt im Medienordner eine .gitignore an, damit gespiegelte Medien nicht im Git-Repo der
     * Seite landen (sie werden auf jedem Server neu geladen). Wirkt auch ohne Eintrag in der
     * .gitignore des Projekts. Nur bei lokalen Disks, eine vorhandene Datei bleibt unverändert.
     */
    protected function ensureGitIgnore(): void
    {
        if ($this->gitIgnoreChecked) {
            return;
        }

        $this->gitIgnoreChecked = true;

        if (config('filesystems.disks.'.self::DISK.'.driver') !== 'local' || $this->disk()->exists('.gitignore')) {
            return;
        }

        $this->disk()->put('.gitignore', self::GITIGNORE);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function isVideo(array $item, string $url): bool
    {
        return ($item['media_type'] ?? null) === 'VIDEO'
            || in_array($this->extensionFromUrl($url), self::VIDEO_EXTENSIONS, true);
    }

    protected static function isSharedDirectory(string $root): bool
    {
        $normalize = fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
        $root = $normalize($root);

        if ($root === '') {
            return true;
        }

        foreach ([public_path(), base_path(), storage_path(), storage_path('app'), storage_path('app/public')] as $shared) {
            if ($root === $normalize($shared)) {
                return true;
            }
        }

        return false;
    }

    protected function urlPrefix(): string
    {
        return '/'.(self::mediaPath() ?? self::DEFAULT_MEDIA_PATH);
    }

    protected function extensionFromUrl(string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, [...self::IMAGE_EXTENSIONS, ...self::VIDEO_EXTENSIONS], true)
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

    /**
     * Eigene Meldung (z. B. "Datei zu groß") auch dann, wenn der HTTP-Client
     * sie in eine eigene Exception verpackt hat. cURL-Fehler 63 = Maximalgröße.
     */
    protected function describeFailure(Throwable $exception): string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof MediaTooLargeException) {
                return $current->getMessage();
            }
        }

        if (str_contains($exception->getMessage(), 'cURL error 63')) {
            return 'Datei zu groß (mehr als '.$this->megabytes($this->maxBytes()).' MB)';
        }

        return $exception->getMessage();
    }

    protected function megabytes(int $bytes): string
    {
        $formatted = rtrim(rtrim(number_format($bytes / 1024 / 1024, 2, ',', ''), '0'), ',');

        return $formatted === '' ? '0' : $formatted;
    }
}
