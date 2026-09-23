<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use WursterMedien\SocialHub\Tests\TestCase;
use WursterMedien\SocialHub\Webhooks\SignatureVerifier;

class WebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('news')->routes('/news/{slug}')->save();

        Entry::make()->collection('news')->id('entry-1')->slug('richtfest')
            ->data(['title' => 'Richtfest', 'social_hub_post_id' => 'post-uuid-1'])
            ->save();

        Entry::make()->collection('news')->id('entry-2')->slug('neu')
            ->data(['title' => 'Neu'])
            ->save();
    }

    #[Test]
    public function a_valid_webhook_updates_status_and_permalinks(): void
    {
        $response = $this->sendWebhook($this->payload('post.published', [
            'id' => 'post-uuid-1',
            'status' => 'published',
            'status_label' => 'Veröffentlicht',
            'source_reference' => 'entry-1',
            'published_at' => '2026-09-24T09:00:00+02:00',
            'targets' => [
                ['account' => 'rath_bau', 'platform' => 'instagram', 'type' => 'image', 'status' => 'published', 'permalink' => 'https://www.instagram.com/p/abc/', 'error' => null, 'published_at' => '2026-09-24T09:00:00+02:00'],
                ['account' => 'rath_bau_fb', 'platform' => 'facebook_page', 'type' => 'image', 'status' => 'failed', 'permalink' => null, 'error' => 'Token abgelaufen', 'published_at' => null],
            ],
        ]));

        $response->assertOk()->assertJson(['ok' => true, 'matched' => true, 'entry' => 'entry-1']);

        $entry = Entry::find('entry-1');
        $this->assertSame('published', $entry->get('social_hub_status'));
        $this->assertSame('Veröffentlicht', $entry->get('social_hub_status_label'));
        $this->assertSame('https://www.instagram.com/p/abc/', $entry->get('social_hub_permalinks')[0]['permalink']);
        $this->assertSame('rath_bau_fb: Token abgelaufen', $entry->get('social_hub_error'));
    }

    #[Test]
    public function the_entry_is_found_by_source_reference(): void
    {
        $this->sendWebhook($this->payload('post.updated', [
            'id' => 'post-uuid-2',
            'status' => 'pending_approval',
            'source_reference' => 'entry-2',
            'targets' => [],
        ]))->assertOk()->assertJson(['matched' => true]);

        $this->assertSame('post-uuid-2', Entry::find('entry-2')->get('social_hub_post_id'));
    }

    #[Test]
    public function an_entry_belonging_to_another_post_is_not_overwritten(): void
    {
        $this->sendWebhook($this->payload('post.updated', [
            'id' => 'fremder-post',
            'status' => 'failed',
            'source_reference' => 'entry-1',
            'targets' => [],
        ]))->assertOk()->assertJson(['matched' => false]);

        $this->assertNull(Entry::find('entry-1')->get('social_hub_status'));
    }

    #[Test]
    public function an_invalid_signature_is_rejected(): void
    {
        $body = json_encode($this->payload('post.published', ['id' => 'post-uuid-1', 'status' => 'published']));

        $this->call('POST', '/!/social-hub/webhook', [], [], [], $this->headers('t='.time().',v1=deadbeef'), $body)
            ->assertStatus(401);

        $this->call('POST', '/!/social-hub/webhook', [], [], [], $this->headers(SignatureVerifier::header($body, time(), 'falsches-secret')), $body)
            ->assertStatus(401);

        $this->call('POST', '/!/social-hub/webhook', [], [], [], $this->headers(null), $body)
            ->assertStatus(401);

        $this->assertNull(Entry::find('entry-1')->get('social_hub_status'));
    }

    #[Test]
    public function an_old_timestamp_is_rejected(): void
    {
        $body = json_encode($this->payload('post.published', ['id' => 'post-uuid-1', 'status' => 'published']));
        $timestamp = time() - 301;

        $this->call('POST', '/!/social-hub/webhook', [], [], [], $this->headers(SignatureVerifier::header($body, $timestamp, 'test-webhook-secret')), $body)
            ->assertStatus(401);
    }

    #[Test]
    public function a_missing_secret_rejects_everything(): void
    {
        config(['social-hub.webhook_secret' => null]);

        $this->sendWebhook($this->payload('post.published', ['id' => 'post-uuid-1']))->assertStatus(401);
    }

    #[Test]
    public function the_webhook_route_skips_csrf_protection(): void
    {
        $route = app('router')->getRoutes()->getByName('statamic.social-hub.webhook');

        $this->assertSame('!/social-hub/webhook', $route->uri());

        $middleware = implode(',', app('router')->gatherRouteMiddleware($route));

        $this->assertStringNotContainsString('Csrf', $middleware);
        $this->assertStringNotContainsString('RequestForgery', $middleware);
    }

    #[Test]
    public function unknown_events_are_rejected(): void
    {
        $this->sendWebhook(['event' => 'account.deleted', 'post' => ['id' => 'x']])->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    protected function payload(string $event, array $post): array
    {
        return ['event' => $event, 'post' => $post];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/!/social-hub/webhook', [], [], [], $this->headers(SignatureVerifier::header($body, time(), 'test-webhook-secret')), $body);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(?string $signature): array
    {
        return array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SOCIAL_HUB_SIGNATURE' => $signature,
        ]);
    }
}
