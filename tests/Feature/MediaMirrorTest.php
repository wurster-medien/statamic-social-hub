<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use WursterMedien\SocialHub\Feeds\MediaMirror;
use WursterMedien\SocialHub\Tests\TestCase;

class MediaMirrorTest extends TestCase
{
    #[Test]
    public function it_downloads_media_thumbnails_and_children(): void
    {
        Http::fake([
            'hub.test/storage/*' => fn () => Http::response('bytes'),
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
    public function the_media_folder_gets_a_gitignore_so_mirrored_files_are_not_committed(): void
    {
        Http::fake(['hub.test/storage/*' => fn () => Http::response('bytes')]);
        $disk = Storage::disk(MediaMirror::DISK);

        app(MediaMirror::class)->mirrorFeed('muster_bau', [
            $this->hubMedia('1', ['media_url' => 'https://hub.test/storage/social/1.jpg']),
        ]);

        $this->assertSame(['*'], array_values(array_filter(
            explode("\n", $disk->get('.gitignore')),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        )));

        $disk->put('.gitignore', 'eigene Regeln');

        app(MediaMirror::class)->mirrorFeed('muster_bau', [
            $this->hubMedia('2', ['media_url' => 'https://hub.test/storage/social/2.jpg']),
        ]);

        $this->assertSame('eigene Regeln', $disk->get('.gitignore'));
        // Aufräumen lässt die .gitignore stehen.
        $this->assertSame(2, app(MediaMirror::class)->prune([]));
        $this->assertTrue($disk->exists('.gitignore'));
    }

    #[Test]
    public function a_local_image_narrower_than_the_hub_reports_is_downloaded_again(): void
    {
        Http::fake(['hub.test/storage/*' => fn () => Http::response($this->jpeg(36, 64))]);
        $disk = Storage::disk(MediaMirror::DISK);
        $disk->put('muster_bau/10_thumb.jpg', $this->jpeg(12, 20));
        $disk->put('muster_bau/10.mp4', 'video');
        $video = $this->hubMedia('10', [
            'media_type' => 'VIDEO',
            'media_url' => 'https://hub.test/storage/social/10.mp4',
            'thumbnail_url' => 'https://hub.test/storage/social/10_thumb.jpg',
            'width' => 36,
            'height' => 64,
        ]);

        $items = app(MediaMirror::class)->mirrorFeed('muster_bau', [$video]);

        $this->assertSame('/social-hub/muster_bau/10_thumb.jpg', $items[0]['thumbnail_url']);
        $this->assertSame(36, getimagesize($disk->path('muster_bau/10_thumb.jpg'))[0]);
        $this->assertSame('video', $disk->get('muster_bau/10.mp4'));
        Http::assertSentCount(1);

        app(MediaMirror::class)->mirrorFeed('muster_bau', [$video]);

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_failed_refresh_keeps_the_local_file(): void
    {
        Http::fake(['hub.test/storage/*' => Http::response('', 500)]);
        Storage::disk(MediaMirror::DISK)->put('muster_bau/1.jpg', $this->jpeg(12, 20));

        $items = app(MediaMirror::class)->mirrorFeed('muster_bau', [$this->hubMedia('1', ['width' => 36])]);

        $this->assertSame('/social-hub/muster_bau/1.jpg', $items[0]['media_url']);
        $this->assertSame(12, getimagesize(Storage::disk(MediaMirror::DISK)->path('muster_bau/1.jpg'))[0]);
    }

    #[Test]
    public function existing_files_are_not_downloaded_again(): void
    {
        Http::fake();
        Storage::disk(MediaMirror::DISK)->put('muster_bau/1.jpg', 'old');

        $items = app(MediaMirror::class)->mirrorFeed('muster_bau', [$this->hubMedia('1')]);

        $this->assertSame('/social-hub/muster_bau/1.jpg', $items[0]['media_url']);
        Http::assertNothingSent();
    }

    #[Test]
    public function failed_downloads_keep_the_hub_url(): void
    {
        Http::fake([
            'hub.test/*' => Http::response('', 404),
        ]);

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('muster_bau', [$this->hubMedia('1')]);

        $this->assertSame('https://hub.test/storage/social/muster_bau/1.jpg', $items[0]['media_url']);
        $this->assertSame(1, $mirror->stats()['failed']);
    }

    #[Test]
    public function an_exhausted_time_budget_skips_downloads(): void
    {
        Http::fake();

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('muster_bau', [$this->hubMedia('1')], microtime(true) - 1);

        $this->assertStringStartsWith('https://hub.test/', $items[0]['media_url']);
        $this->assertSame(1, $mirror->stats()['skipped']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_prunes_files_that_are_no_longer_referenced(): void
    {
        $disk = Storage::disk(MediaMirror::DISK);
        $disk->put('muster_bau/1.jpg', 'a');
        $disk->put('muster_bau/2.jpg', 'b');
        $disk->put('alt/3.jpg', 'c');

        $mirror = app(MediaMirror::class);
        $keep = $mirror->referencedPaths([
            $this->hubMedia('1', ['media_url' => '/social-hub/muster_bau/1.jpg']),
        ]);

        $this->assertSame(2, $mirror->prune($keep));

        $disk->assertExists('muster_bau/1.jpg');
        $disk->assertMissing(['muster_bau/2.jpg', 'alt/3.jpg']);
    }

    #[Test]
    public function handles_and_ids_are_sanitized_for_paths(): void
    {
        Http::fake(['*' => fn () => Http::response('x')]);

        $items = app(MediaMirror::class)->mirrorFeed('../evil', [$this->hubMedia('1/../../x')]);

        $this->assertSame('/social-hub/_evil/1x.jpg', $items[0]['media_url']);
    }

    #[Test]
    public function media_from_other_hosts_is_not_downloaded(): void
    {
        Http::fake(['*' => fn () => Http::response('bytes')]);

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('muster_bau', [
            $this->hubMedia('1', ['media_url' => 'http://169.254.169.254/latest/meta-data.jpg']),
            $this->hubMedia('2', ['media_url' => 'https://hub.test.evil.example/2.jpg']),
        ]);

        $this->assertSame('http://169.254.169.254/latest/meta-data.jpg', $items[0]['media_url']);
        $this->assertSame('https://hub.test.evil.example/2.jpg', $items[1]['media_url']);
        $this->assertSame(2, $mirror->stats()['skipped']);
        $this->assertStringContainsString('169.254.169.254', $mirror->failures()[0]);
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk(MediaMirror::DISK)->allFiles());
    }

    #[Test]
    public function additional_media_hosts_can_be_allowed(): void
    {
        config(['social-hub.media_hosts' => ['CDN.example.com']]);
        Http::fake(['cdn.example.com/*' => fn () => Http::response('bytes')]);

        $items = app(MediaMirror::class)->mirrorFeed('muster_bau', [
            $this->hubMedia('1', ['media_url' => 'https://cdn.example.com/social/1.jpg']),
        ]);

        $this->assertSame('/social-hub/muster_bau/1.jpg', $items[0]['media_url']);
    }

    #[Test]
    public function downloads_are_streamed_into_a_temporary_file_with_size_guards(): void
    {
        config(['social-hub.max_download_mb' => 1]);
        $options = null;

        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response('bytes');
        });

        $items = app(MediaMirror::class)->mirrorFeed('muster_bau', [$this->hubMedia('1')]);

        $this->assertSame('/social-hub/muster_bau/1.jpg', $items[0]['media_url']);
        $this->assertSame('bytes', Storage::disk(MediaMirror::DISK)->get('muster_bau/1.jpg'));

        // Stream direkt in eine Datei statt in den Speicher; die Datei ist danach weg.
        $this->assertIsString($options['sink']);
        $this->assertFileDoesNotExist($options['sink']);

        // Content-Length wird geprüft, bevor der Inhalt geladen wird …
        $options['on_headers'](new Psr7Response(200, ['Content-Length' => '1048576']));

        try {
            $options['on_headers'](new Psr7Response(200, ['Content-Length' => '1048577']));
            $this->fail('Abbruch bei zu großem Content-Length erwartet');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('zu groß', $exception->getMessage());
        }

        // … und der Download bricht ab, sobald mehr als erlaubt angekommen ist.
        $options['progress'](0, 1048576, 0, 0);

        try {
            $options['progress'](0, 1048577, 0, 0);
            $this->fail('Abbruch während des Downloads erwartet');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('zu groß', $exception->getMessage());
        }

        if (defined('CURLOPT_MAXFILESIZE_LARGE')) {
            $this->assertSame(1048576, $options['curl'][CURLOPT_MAXFILESIZE_LARGE]);
        }
    }

    #[Test]
    public function files_announced_as_too_large_are_not_stored(): void
    {
        config(['social-hub.max_download_mb' => 1]);

        Http::fake([
            'hub.test/*' => fn () => Http::response('x', 200, ['Content-Length' => (string) (50 * 1024 * 1024)]),
        ]);

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('muster_bau', [$this->hubMedia('1')]);

        $this->assertSame('https://hub.test/storage/social/muster_bau/1.jpg', $items[0]['media_url']);
        $this->assertSame(1, $mirror->stats()['failed']);
        $this->assertStringContainsString('zu groß', $mirror->failures()[0]);
        Storage::disk(MediaMirror::DISK)->assertMissing('muster_bau/1.jpg');
    }

    #[Test]
    public function oversized_bodies_without_content_length_are_not_stored(): void
    {
        config(['social-hub.max_download_mb' => 0.001]);

        Http::fake([
            'hub.test/*' => fn () => Http::response(str_repeat('x', 5000)),
        ]);

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('muster_bau', [$this->hubMedia('1')]);

        $this->assertStringStartsWith('https://hub.test/', $items[0]['media_url']);
        $this->assertSame(1, $mirror->stats()['failed']);
        Storage::disk(MediaMirror::DISK)->assertMissing('muster_bau/1.jpg');
    }

    #[Test]
    public function videos_can_be_left_on_the_hub_while_images_and_thumbnails_are_mirrored(): void
    {
        Http::fake(['hub.test/storage/*' => fn () => Http::response('bytes')]);
        Storage::disk(MediaMirror::DISK)->put('muster_bau/30.mp4', 'old');

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('muster_bau', [
            $this->hubMedia('10', [
                'media_type' => 'VIDEO',
                'media_url' => 'https://hub.test/storage/social/10.mp4',
                'thumbnail_url' => 'https://hub.test/storage/social/10_thumb.jpg',
            ]),
            $this->hubMedia('20', [
                'media_type' => 'CAROUSEL_ALBUM',
                'children' => [
                    $this->hubMedia('21', ['media_type' => 'VIDEO', 'media_url' => 'https://hub.test/storage/social/21.mp4']),
                    $this->hubMedia('22'),
                ],
            ]),
            $this->hubMedia('30', ['media_type' => 'VIDEO', 'media_url' => 'https://hub.test/storage/social/30.mp4']),
        ], null, false);

        $this->assertSame('https://hub.test/storage/social/10.mp4', $items[0]['media_url']);
        $this->assertSame('/social-hub/muster_bau/10_thumb.jpg', $items[0]['thumbnail_url']);
        $this->assertSame('/social-hub/muster_bau/20.jpg', $items[1]['media_url']);
        $this->assertSame('https://hub.test/storage/social/21.mp4', $items[1]['children'][0]['media_url']);
        $this->assertSame('/social-hub/muster_bau/22.jpg', $items[1]['children'][1]['media_url']);
        // Bereits gespiegelte Videos werden weiter lokal ausgeliefert.
        $this->assertSame('/social-hub/muster_bau/30.mp4', $items[2]['media_url']);

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '.mp4'));
    }

    #[Test]
    public function prune_only_deletes_files_matching_the_own_naming_scheme(): void
    {
        $disk = Storage::disk(MediaMirror::DISK);
        $disk->put('muster_bau/1.jpg', 'behalten');
        $disk->put('muster_bau/2_thumb.webp', 'weg');
        $disk->put('muster_bau/notes.txt', 'fremd');
        $disk->put('muster_bau/.gitignore', 'fremd');
        $disk->put('muster_bau/sub/5.jpg', 'fremd');
        $disk->put('index.html', 'fremd');
        $disk->put('4.jpg', 'fremd');
        $disk->put('alt/3.mp4', 'weg');

        $deleted = app(MediaMirror::class)->prune(['muster_bau/1.jpg']);

        $this->assertSame(2, $deleted);
        $disk->assertMissing(['muster_bau/2_thumb.webp', 'alt/3.mp4', 'alt']);
        $disk->assertExists(['muster_bau/1.jpg', 'muster_bau/notes.txt', 'muster_bau/.gitignore', 'muster_bau/sub/5.jpg', 'index.html', '4.jpg']);
    }

    #[Test]
    #[DataProvider('unsafeMediaPaths')]
    public function an_unsafe_media_path_disables_mirroring_and_pruning(mixed $mediaPath): void
    {
        config(['social-hub.media_path' => $mediaPath]);
        Http::fake();
        Log::spy();

        $disk = Storage::disk(MediaMirror::DISK);
        $disk->put('muster_bau/2.jpg', 'fremd');

        $mirror = app(MediaMirror::class);
        $items = $mirror->mirrorFeed('muster_bau', [$this->hubMedia('1'), $this->hubMedia('2')]);

        $this->assertSame('https://hub.test/storage/social/muster_bau/1.jpg', $items[0]['media_url']);
        $this->assertSame(0, $mirror->prune([]));
        $disk->assertExists('muster_bau/2.jpg');
        Http::assertNothingSent();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'media_path'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unsafeMediaPaths(): array
    {
        return [
            'leer' => [''],
            'slash' => ['/'],
            'null' => [null],
            'eltern' => ['../storage'],
            'mitte' => ['social/../..'],
            'punkt' => ['.'],
        ];
    }

    #[Test]
    public function a_media_disk_pointing_at_the_public_root_disables_pruning(): void
    {
        config(['filesystems.disks.'.MediaMirror::DISK.'.root' => public_path()]);

        $disk = Storage::disk(MediaMirror::DISK);
        $disk->put('muster_bau/2.jpg', 'fremd');

        $this->assertNotNull(MediaMirror::configurationProblem());
        $this->assertSame(0, app(MediaMirror::class)->prune([]));
        $disk->assertExists('muster_bau/2.jpg');
    }
}
