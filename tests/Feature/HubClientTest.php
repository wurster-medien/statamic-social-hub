<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use WursterMedien\SocialHub\Hub\HubClient;
use WursterMedien\SocialHub\Hub\HubConnectionException;
use WursterMedien\SocialHub\Hub\HubNotConfiguredException;
use WursterMedien\SocialHub\Hub\HubRequestException;
use WursterMedien\SocialHub\Tests\TestCase;

class HubClientTest extends TestCase
{
    #[Test]
    public function it_sends_bearer_key_and_version_headers(): void
    {
        Http::fake([
            'hub.test/api/v1/feeds/*' => Http::response($this->hubFeed([$this->hubMedia('1')])),
        ]);

        $feed = app(HubClient::class)->feed('rath_bau', 12);

        $this->assertCount(1, $feed['data']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://hub.test/api/v1/feeds/rath_bau?limit=12'
                && $request->hasHeader('Authorization', 'Bearer test-site-key')
                && $request->hasHeader('Accept', 'application/json')
                && filled($request->header('X-Social-Hub-Addon')[0] ?? null)
                && filled($request->header('X-Statamic-Version')[0] ?? null);
        });
    }

    #[Test]
    public function it_posts_json_to_the_posts_endpoint(): void
    {
        Http::fake([
            'hub.test/api/v1/posts' => Http::response(['data' => ['id' => 'uuid-1', 'status' => 'pending_approval']], 201),
        ]);

        $post = app(HubClient::class)->createPost(['title' => 'Test', 'targets' => [['account' => 'rath_bau']]]);

        $this->assertSame('uuid-1', $post['id']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->isJson()
            && $request['targets'][0]['account'] === 'rath_bau');
    }

    #[Test]
    public function error_messages_never_contain_the_key(): void
    {
        Http::fake([
            'hub.test/*' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        try {
            app(HubClient::class)->accounts();
            $this->fail('Exception erwartet');
        } catch (HubRequestException $exception) {
            $this->assertSame(401, $exception->status);
            $this->assertTrue($exception->isUnauthorized());
            $this->assertStringNotContainsString('test-site-key', $exception->getMessage());
            $this->assertStringContainsString('401', $exception->getMessage());
        }
    }

    #[Test]
    public function validation_errors_are_exposed(): void
    {
        Http::fake([
            'hub.test/*' => Http::response(['message' => 'Invalid', 'errors' => ['targets' => ['Konto unbekannt.']]], 422),
        ]);

        try {
            app(HubClient::class)->createPost([]);
            $this->fail('Exception erwartet');
        } catch (HubRequestException $exception) {
            $this->assertTrue($exception->isValidationError());
            $this->assertSame(['targets: Konto unbekannt.'], $exception->errorMessages());
        }
    }

    #[Test]
    public function connection_errors_are_wrapped(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28'));

        $this->expectException(HubConnectionException::class);

        app(HubClient::class)->ping();
    }

    #[Test]
    public function it_refuses_to_run_without_configuration(): void
    {
        config(['social-hub.key' => null]);
        Http::fake();

        $this->expectException(HubNotConfiguredException::class);

        try {
            app(HubClient::class)->ping();
        } finally {
            Http::assertNothingSent();
        }
    }
}
