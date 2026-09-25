<?php

namespace WursterMedien\SocialHub\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use WursterMedien\SocialHub\Feeds\MediaMirror;
use WursterMedien\SocialHub\ServiceProvider;
use WursterMedien\SocialHub\Support\StatamicVersion;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['files']->deleteDirectory(self::tmpPath());
        $this->app['files']->ensureDirectoryExists(self::tmpPath('assets'));

        Storage::fake(MediaMirror::DISK);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory(self::tmpPath());

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.url', 'https://kunde.test');
        // Wie in einer echten Seite: PHP-Zeitzone = app.timezone.
        $app['config']->set('app.timezone', 'Europe/Berlin');
        date_default_timezone_set('Europe/Berlin');
        $app['config']->set('cache.default', 'array');

        $app['config']->set('social-hub.url', 'https://hub.test');
        $app['config']->set('social-hub.key', 'test-site-key');
        $app['config']->set('social-hub.webhook_secret', 'test-webhook-secret');
        $app['config']->set('social-hub.storage_path', self::tmpPath('storage'));

        $app['config']->set('statamic.system.blueprints_path', self::tmpPath('blueprints'));
        $app['config']->set('statamic.system.fieldsets_path', self::tmpPath('fieldsets'));
        $app['config']->set('statamic.editions.pro', true);

        $app['config']->set('filesystems.disks.assets', [
            'driver' => 'local',
            'root' => self::tmpPath('assets'),
            'url' => '/assets',
        ]);
    }

    /**
     * Die CP-View einer bestimmten Statamic-Version rendern, unabhängig von der installierten.
     */
    protected function useStatamicVersion(int $major): void
    {
        $this->app->instance(StatamicVersion::class, new class($major) extends StatamicVersion
        {
            public function __construct(private int $fakeMajor) {}

            public function major(): int
            {
                return $this->fakeMajor;
            }
        });
    }

    public static function tmpPath(string $path = ''): string
    {
        return rtrim(__DIR__.'/__fixtures__/tmp/'.$path, '/');
    }

    /**
     * Ein Medium im Format der Hub-API.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function hubMedia(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'media_type' => 'IMAGE',
            'media_product_type' => 'FEED',
            'media_url' => "https://hub.test/storage/social/muster_bau/{$id}.jpg",
            'thumbnail_url' => null,
            'permalink' => "https://www.instagram.com/p/{$id}/",
            'caption' => "Neues Projekt {$id} fertig! #holzbau #handwerk",
            'timestamp' => '2026-09-20T10:15:00+00:00',
            'like_count' => 12,
            'comments_count' => 3,
            'width' => 1080,
            'height' => 1350,
            'children' => [],
        ], $overrides);
    }

    protected function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function hubFeed(array $items, string $handle = 'muster_bau'): array
    {
        return [
            'data' => $items,
            'meta' => [
                'account' => ['handle' => $handle, 'platform' => 'instagram', 'username' => $handle],
                'synced_at' => '2026-09-24T08:00:00+00:00',
            ],
        ];
    }
}
