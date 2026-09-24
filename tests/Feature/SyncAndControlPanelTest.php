<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;
use WursterMedien\SocialHub\Feeds\MediaMirror;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Support\SyncStatus;
use WursterMedien\SocialHub\Tests\TestCase;

class SyncAndControlPanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'hub.test/api/v1/ping' => Http::response(['ok' => true, 'hub' => ['name' => 'Social Hub', 'api_version' => 'v1'], 'site' => ['id' => 1, 'name' => 'Muster Bau', 'client' => 'Muster']]),
            'hub.test/api/v1/accounts' => Http::response(['data' => [
                ['handle' => 'muster_bau', 'platform' => 'instagram', 'username' => 'muster_bau', 'name' => 'Muster Bau', 'status' => 'active', 'last_synced_at' => '2026-09-24T08:00:00+00:00', 'can_publish' => true],
            ]]),
            'hub.test/api/v1/feeds/muster_bau*' => Http::response($this->hubFeed([$this->hubMedia('1'), $this->hubMedia('2')])),
            'hub.test/storage/*' => fn () => Http::response('bytes'),
        ]);
    }

    #[Test]
    public function the_sync_command_refreshes_feeds_mirrors_media_and_prunes(): void
    {
        Storage::disk(MediaMirror::DISK)->put('muster_bau/alt.jpg', 'alt');

        $this->artisan('social-hub:sync')
            ->expectsOutputToContain('Muster Bau')
            ->assertSuccessful();

        Storage::disk(MediaMirror::DISK)->assertExists(['muster_bau/1.jpg', 'muster_bau/2.jpg']);
        Storage::disk(MediaMirror::DISK)->assertMissing('muster_bau/alt.jpg');

        $this->assertCount(2, app(StateStore::class)->feed('muster_bau')['data']);

        $status = app(SyncStatus::class)->all();
        $this->assertTrue($status['last_sync_ok']);
        $this->assertArrayHasKey('muster_bau', $status['accounts']);
    }

    #[Test]
    public function the_sync_command_fails_when_the_hub_is_down(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->artisan('social-hub:sync')->assertFailed();

        $this->assertNotEmpty(app(SyncStatus::class)->all()['errors']);
    }

    #[Test]
    public function the_sync_is_scheduled_every_thirty_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'social-hub:sync'));

        $this->assertNotNull($event);
        $this->assertSame('*/30 * * * *', $event->expression);
    }

    #[Test]
    public function the_control_panel_page_shows_status_and_accounts(): void
    {
        $this->actingAs($this->superUser())
            ->get(cp_route('social-hub.index'))
            ->assertOk()
            ->assertSee('Social Hub')
            ->assertSee('verbunden')
            ->assertSee('muster_bau')
            ->assertSee('Jetzt synchronisieren')
            ->assertDontSee('test-site-key');
    }

    #[Test]
    public function the_sync_button_runs_a_sync(): void
    {
        $this->actingAs($this->superUser())
            ->post(cp_route('social-hub.sync'))
            ->assertRedirect(cp_route('social-hub.index'))
            ->assertSessionHas('social_hub_ok', true);

        Storage::disk(MediaMirror::DISK)->assertExists('muster_bau/1.jpg');
    }

    #[Test]
    public function users_without_permission_cannot_open_the_page(): void
    {
        $user = User::make()->id('redaktion')->email('redaktion@example.com')->set('super', false);
        $user->save();

        $response = $this->actingAs($user)->get(cp_route('social-hub.index'));

        // Ohne "access cp" leitet Statamic um, ohne "view social hub" gibt es 403.
        $this->assertContains($response->status(), [302, 403]);
        $response->assertDontSee('Jetzt synchronisieren');

        $this->actingAs($user)->post(cp_route('social-hub.sync'));
        Http::assertNothingSent();
    }

    protected function superUser(): \Statamic\Contracts\Auth\User
    {
        $user = User::make()->id('admin')->email('admin@example.com')->makeSuper();
        $user->save();

        return $user;
    }
}
