<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use WursterMedien\SocialHub\Feeds\FeedRepository;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Support\SyncStatus;
use WursterMedien\SocialHub\Tests\TestCase;

class FeedRepositoryTest extends TestCase
{
    #[Test]
    public function it_fetches_mirrors_and_caches_the_feed(): void
    {
        Http::fake([
            'hub.test/api/v1/feeds/rath_bau*' => Http::response($this->hubFeed([$this->hubMedia('1'), $this->hubMedia('2')])),
            'hub.test/storage/*' => fn () => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $repository = app(FeedRepository::class);

        $items = $repository->items('rath_bau', 1);

        $this->assertCount(1, $items);
        $this->assertSame('/social-hub/rath_bau/1.jpg', $items[0]['media_url']);

        // Zweiter Aufruf kommt aus dem Cache: kein weiterer Feed-Abruf.
        $repository->items('rath_bau');
        Http::assertSentCount(3);

        $stored = app(StateStore::class)->feed('rath_bau');
        $this->assertCount(2, $stored['data']);
        $this->assertNotNull($stored['fetched_at']);
    }

    #[Test]
    public function it_serves_the_last_good_feed_when_the_hub_is_down(): void
    {
        Http::fake([
            'hub.test/api/v1/feeds/*' => Http::response(['message' => 'Server Error'], 500),
        ]);

        app(StateStore::class)->putFeed('rath_bau', [
            'handle' => 'rath_bau',
            'data' => [$this->hubMedia('9', ['media_url' => '/social-hub/rath_bau/9.jpg'])],
            'meta' => [],
            'fetched_at' => Carbon::now()->subDays(3)->toIso8601String(),
        ]);

        $feed = app(FeedRepository::class)->feed('rath_bau');

        $this->assertTrue($feed['stale']);
        $this->assertSame('9', $feed['data'][0]['id']);

        $errors = app(SyncStatus::class)->all()['errors'];
        $this->assertStringContainsString('500', $errors[0]['message']);
        $this->assertStringNotContainsString('test-site-key', $errors[0]['message']);

        // Nach dem Fehler wird der Hub nicht bei jedem Aufruf erneut gefragt.
        app(FeedRepository::class)->feed('rath_bau');
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_returns_an_empty_feed_when_the_last_good_feed_is_too_old(): void
    {
        Log::spy();

        Http::fake([
            'hub.test/*' => Http::failedConnection(),
        ]);

        app(StateStore::class)->putFeed('rath_bau', [
            'handle' => 'rath_bau',
            'data' => [$this->hubMedia('9')],
            'meta' => [],
            'fetched_at' => Carbon::now()->subDays(8)->toIso8601String(),
        ]);

        $this->assertSame([], app(FeedRepository::class)->items('rath_bau'));

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'leere Liste'));
    }

    #[Test]
    public function it_never_throws_when_the_hub_is_not_configured(): void
    {
        config(['social-hub.url' => null]);
        Http::fake();

        $this->assertSame([], app(FeedRepository::class)->items('rath_bau'));
        Http::assertNothingSent();
    }

    #[Test]
    public function cache_expiry_triggers_a_new_fetch(): void
    {
        Http::fake([
            'hub.test/api/v1/feeds/*' => Http::sequence()
                ->push($this->hubFeed([$this->hubMedia('1', ['media_url' => null])]))
                ->push($this->hubFeed([$this->hubMedia('2', ['media_url' => null]), $this->hubMedia('1', ['media_url' => null])])),
        ]);

        $repository = app(FeedRepository::class);

        $this->assertCount(1, $repository->items('rath_bau'));

        Cache::forget($repository->cacheKey('rath_bau'));

        $this->assertSame(['2', '1'], array_column($repository->items('rath_bau'), 'id'));
    }

    #[Test]
    public function a_page_request_mirrors_images_and_thumbnails_but_not_videos(): void
    {
        $this->simulateWebRequest();

        Http::fake([
            'hub.test/api/v1/feeds/rath_bau*' => Http::response($this->hubFeed([
                $this->hubMedia('1', [
                    'media_type' => 'VIDEO',
                    'media_url' => 'https://hub.test/storage/social/1.mp4',
                    'thumbnail_url' => 'https://hub.test/storage/social/1_thumb.jpg',
                ]),
                $this->hubMedia('2'),
            ])),
            'hub.test/storage/*' => fn () => Http::response('bytes'),
        ]);

        $items = app(FeedRepository::class)->items('rath_bau');

        $this->assertSame('https://hub.test/storage/social/1.mp4', $items[0]['media_url']);
        $this->assertSame('/social-hub/rath_bau/1_thumb.jpg', $items[0]['thumbnail_url']);
        $this->assertSame('/social-hub/rath_bau/2.jpg', $items[1]['media_url']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '.mp4'));
    }

    #[Test]
    public function the_sync_still_mirrors_videos(): void
    {
        Http::fake([
            'hub.test/api/v1/feeds/rath_bau*' => Http::response($this->hubFeed([
                $this->hubMedia('1', ['media_type' => 'VIDEO', 'media_url' => 'https://hub.test/storage/social/1.mp4']),
            ])),
            'hub.test/storage/*' => fn () => Http::response('bytes'),
        ]);

        $feed = app(FeedRepository::class)->refresh('rath_bau');

        $this->assertSame('/social-hub/rath_bau/1.mp4', $feed['data'][0]['media_url']);
    }

    /**
     * PHPUnit läuft auf der Konsole; für den Test so tun, als wäre es ein
     * Seitenaufruf.
     */
    protected function simulateWebRequest(): void
    {
        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, false);

        $this->assertFalse($this->app->runningInConsole());
    }
}
