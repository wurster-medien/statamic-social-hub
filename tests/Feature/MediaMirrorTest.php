<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use WursterMedien\SocialHub\Feeds\MediaMirror;
use WursterMedien\SocialHub\Tests\TestCase;

class MediaMirrorTest extends TestCase
{
    #[Test]
    public function it_downloads_media_thumbnails_and_children(): void
    {
        Http::fake([
            'hub.test/storage/*' => Http::response('bytes'),
        ]);

        $items = app(MediaMirror::class)->mirrorFeed('wurster.medien', [
            $this->hubMedia('10', [
                'media_type' => 'VIDEO',
                'media_url' => 'https://hub.test/storage/social/10.mp4',
                'thumbnail_url' => 'https://hub.test/storage/social/10_thumb.jpg',
            ]),
            $this->hubMedia('11', [
                'media_type' => 'CAROUSEL_ALBUM',
                'children' => [
                    $this->hubMedia('111', ['media_url' => 'https://hub.test/storage/social/111.png']),
                ],
            ]),
        ]);

        $this->assertSame('/social-hub/wurster.medien/10.mp4', $items[0]['media_url']);
        $this->assertSame('/social-hub/wurster.medien/10_thumb.jpg', $items[0]['thumbnail_url']);
        $this->assertSame('/social-hub/wurster.medien/111.png', $items[1]['children'][0]['media_url']);

        Storage::disk(MediaMirror::DISK)->assertExists([
            'wurster.medien/10.mp4',
            'wurster.medien/10_thumb.jpg',
            'wurster.medien/11.jpg',
            'wurster.medien/111.png',
        ]);

        // Keine Zugangsdaten an die Medien-URLs.
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    #[Test]
    public function existing_files_are_not_downloaded_again(): void
    {
        Http::fake();
        Storage::disk(MediaMirror::DISK)->put('rath_bau/1.jpg', 'old');

        $items = app(MediaMirror::class)->mirrorFeed('rath_bau', [$this->hubMedia('1')]);

        $this->assertSame('/social-hub/rath_bau/1.jpg', $items[0]['media_url']);
        Http::assertNothingSent();
    }

    #[Test]
    public function failed_downloads_keep_the_hub_url(): void
    {
        Http::fake([
            'hub.test/*' => Http::response('', 404),
        ]);

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('rath_bau', [$this->hubMedia('1')]);

        $this->assertSame('https://hub.test/storage/social/rath_bau/1.jpg', $items[0]['media_url']);
        $this->assertSame(1, $mirror->stats()['failed']);
    }

    #[Test]
    public function an_exhausted_time_budget_skips_downloads(): void
    {
        Http::fake();

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('rath_bau', [$this->hubMedia('1')], microtime(true) - 1);

        $this->assertStringStartsWith('https://hub.test/', $items[0]['media_url']);
        $this->assertSame(1, $mirror->stats()['skipped']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_prunes_files_that_are_no_longer_referenced(): void
    {
        $disk = Storage::disk(MediaMirror::DISK);
        $disk->put('rath_bau/1.jpg', 'a');
        $disk->put('rath_bau/2.jpg', 'b');
        $disk->put('alt/3.jpg', 'c');

        $mirror = app(MediaMirror::class);
        $keep = $mirror->referencedPaths([
            $this->hubMedia('1', ['media_url' => '/social-hub/rath_bau/1.jpg']),
        ]);

        $this->assertSame(2, $mirror->prune($keep));

        $disk->assertExists('rath_bau/1.jpg');
        $disk->assertMissing(['rath_bau/2.jpg', 'alt/3.jpg']);
    }

    #[Test]
    public function handles_and_ids_are_sanitized_for_paths(): void
    {
        Http::fake(['*' => Http::response('x')]);

        $items = app(MediaMirror::class)->mirrorFeed('../evil', [$this->hubMedia('1/../../x')]);

        $this->assertSame('/social-hub/_evil/1x.jpg', $items[0]['media_url']);
    }
}
