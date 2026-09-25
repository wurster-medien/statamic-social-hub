<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use WursterMedien\SocialHub\Support\HubConnection;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Tests\TestCase;

class ConnectionCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Wie auf einer Seite ohne Einträge in der .env.
        config(['social-hub.url' => null, 'social-hub.key' => null, 'social-hub.webhook_secret' => null]);

        Http::fake([
            'hub.test/api/v1/ping' => Http::response(['ok' => true, 'hub' => ['name' => 'Social Hub'], 'site' => ['id' => 1, 'name' => 'Muster Bau']]),
            'hub.test/api/v1/accounts' => Http::response(['data' => [
                ['handle' => 'muster_bau', 'platform' => 'instagram', 'username' => 'muster_bau', 'status' => 'active', 'can_publish' => false],
            ]]),
            'hub.test/api/v1/feeds/muster_bau*' => Http::response($this->hubFeed([$this->hubMedia('1')])),
            'hub.test/storage/*' => fn () => Http::response('bytes'),
        ]);
    }

    #[Test]
    public function pasting_the_code_connects_the_site_and_loads_the_feed(): void
    {
        $this->actingAs($this->superUser())
            ->post(cp_route('social-hub.connect'), ['code' => $this->code(['u' => 'https://hub.test/', 'k' => 'site-key', 's' => 'webhook-secret'])])
            ->assertRedirect(cp_route('social-hub.index'))
            ->assertSessionHas('social_hub_ok', true)
            ->assertSessionHas('social_hub_message', fn (string $message): bool => str_contains($message, 'verbunden als „Muster Bau“'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hub.test/api/v1/ping'
            && $request->hasHeader('Authorization', 'Bearer site-key'));

        $connection = $this->freshConnection();
        $this->assertSame('https://hub.test', $connection->url());
        $this->assertSame('site-key', $connection->key());
        $this->assertSame('webhook-secret', $connection->webhookSecret());
        $this->assertCount(1, app(StateStore::class)->feed('muster_bau')['data']);
    }

    #[Test]
    public function the_credentials_are_stored_encrypted(): void
    {
        app(HubConnection::class)->store(['url' => 'https://hub.test', 'key' => 'site-key', 'webhook_secret' => 'webhook-secret']);

        $file = (string) file_get_contents(app(StateStore::class)->root().'/connection.json');

        $this->assertStringNotContainsString('site-key', $file);
        $this->assertStringNotContainsString('webhook-secret', $file);
    }

    #[Test]
    public function a_code_rejected_by_the_hub_is_not_stored(): void
    {
        Http::fake(['abgelehnt.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs($this->superUser())
            ->post(cp_route('social-hub.connect'), ['code' => $this->code(['u' => 'https://abgelehnt.test', 'k' => 'falscher-key'])])
            ->assertSessionHas('social_hub_ok', false);

        $this->assertFalse($this->freshConnection()->isConfigured());
    }

    #[Test]
    public function invalid_or_insecure_codes_are_rejected_without_a_request(): void
    {
        foreach (['kein code', 'shc1.@@@', $this->code(['u' => 'http://hub.example', 'k' => 'site-key']), $this->code(['u' => 'https://hub.test'])] as $code) {
            $this->actingAs($this->superUser())
                ->post(cp_route('social-hub.connect'), ['code' => $code])
                ->assertSessionHas('social_hub_ok', false);
        }

        Http::assertNothingSent();
        $this->assertFalse($this->freshConnection()->isConfigured());
    }

    #[Test]
    public function a_code_without_key_keeps_the_stored_key_and_updates_the_secret(): void
    {
        app(HubConnection::class)->store(['url' => 'https://hub.test', 'key' => 'site-key', 'webhook_secret' => 'alt']);

        $this->actingAs($this->superUser())
            ->post(cp_route('social-hub.connect'), ['code' => $this->code(['u' => 'https://hub.test', 's' => 'neu'])])
            ->assertSessionHas('social_hub_ok', true);

        $connection = $this->freshConnection();
        $this->assertSame('site-key', $connection->key());
        $this->assertSame('neu', $connection->webhookSecret());
    }

    #[Test]
    public function values_from_the_env_take_precedence(): void
    {
        app(HubConnection::class)->store(['url' => 'https://anderer-hub.test', 'key' => 'code-key', 'webhook_secret' => 'code-secret']);
        config(['social-hub.url' => 'https://hub.test', 'social-hub.key' => 'env-key']);

        $connection = $this->freshConnection();
        $this->assertSame('https://hub.test', $connection->url());
        $this->assertSame('env-key', $connection->key());
        $this->assertTrue($connection->usesEnvironment());

        $this->actingAs($this->superUser())
            ->post(cp_route('social-hub.connect'), ['code' => $this->code(['u' => 'https://hub.test', 'k' => 'neu'])])
            ->assertSessionHas('social_hub_ok', false);
    }

    #[Test]
    public function disconnecting_removes_the_stored_code(): void
    {
        app(HubConnection::class)->store(['url' => 'https://hub.test', 'key' => 'site-key', 'webhook_secret' => null]);

        $this->actingAs($this->superUser())
            ->post(cp_route('social-hub.disconnect'))
            ->assertSessionHas('social_hub_ok', true);

        $this->assertFalse($this->freshConnection()->isConfigured());
    }

    #[Test]
    public function the_page_offers_the_code_field_while_not_connected(): void
    {
        $this->useStatamicVersion(6);

        $this->actingAs($this->superUser())
            ->get(cp_route('social-hub.index'))
            ->assertOk()
            ->assertSee('nicht verbunden')
            ->assertSee('label="Verbindungscode"', false)
            ->assertSee('name="code"', false)
            ->assertDontSee('Verbindung trennen');
    }

    #[Test]
    public function the_statamic_5_page_offers_the_code_field_while_not_connected(): void
    {
        $this->useStatamicVersion(5);

        $this->actingAs($this->superUser())
            ->get(cp_route('social-hub.index'))
            ->assertOk()
            ->assertSee('nicht verbunden')
            ->assertSee('name="code"', false)
            ->assertDontSee('<ui-', false)
            ->assertDontSee('Verbindung trennen');
    }

    #[Test]
    public function users_without_the_connect_permission_cannot_connect(): void
    {
        $user = User::make()->id('redaktion')->email('redaktion@example.com')->set('super', false);
        $user->save();

        $this->actingAs($user)->post(cp_route('social-hub.connect'), ['code' => $this->code(['u' => 'https://hub.test', 'k' => 'site-key'])]);

        Http::assertNothingSent();
        $this->assertFalse($this->freshConnection()->isConfigured());
    }

    /**
     * @param  array<string, string>  $payload
     */
    protected function code(array $payload): string
    {
        return HubConnection::CODE_PREFIX.rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    protected function freshConnection(): HubConnection
    {
        return new HubConnection(app(StateStore::class));
    }

    protected function superUser(): UserContract
    {
        $user = User::make()->id('admin')->email('admin@example.com')->makeSuper();
        $user->save();

        return $user;
    }
}
