<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Dictionary;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use WursterMedien\SocialHub\Actions\SendToSocialHub;
use WursterMedien\SocialHub\Posts\PostPayloadBuilder;
use WursterMedien\SocialHub\Tests\TestCase;

class SendToSocialHubTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AssetContainer::make('assets')->disk('assets')->title('Assets')->save();
        $this->app['files']->put(self::tmpPath('assets/richtfest.jpg'), 'jpg');
        Asset::make()->container('assets')->path('richtfest.jpg')->set('alt', 'Richtfest auf der Baustelle')->save();

        Collection::make('news')->routes('/news/{slug}')->save();

        Blueprint::make('news')->setNamespace('collections.news')->setContents([
            'tabs' => [
                'main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => 'teaser', 'field' => ['type' => 'textarea']],
                ]]]],
                'social' => ['sections' => [['fields' => [
                    ['import' => 'social-hub::social_hub'],
                ]]]],
            ],
        ])->save();

        Collection::make('pages')->routes('/{slug}')->save();
    }

    #[Test]
    public function the_fieldset_is_available_under_the_addon_namespace(): void
    {
        $fieldset = Fieldset::find('social-hub::social_hub');

        $this->assertNotNull($fieldset);
        $this->assertTrue($fieldset->fields()->has('social_hub_targets'));
        $this->assertTrue($fieldset->fields()->has('social_hub_post_id'));
    }

    #[Test]
    public function the_account_dictionary_lists_publishable_accounts(): void
    {
        Http::fake([
            'hub.test/api/v1/accounts' => Http::response(['data' => [
                ['handle' => 'rath_bau', 'platform' => 'instagram', 'name' => 'Rath Bau', 'can_publish' => true],
                ['handle' => 'nur_lesen', 'platform' => 'instagram', 'name' => 'Nur lesen', 'can_publish' => false],
            ]]),
        ]);
        config(['social-hub.publish_accounts' => ['rath_bau_fb' => 'Rath Bau (Facebook)']]);

        $options = Dictionary::find('social_hub_accounts')->options();

        $this->assertSame([
            'rath_bau' => 'Rath Bau (Instagram)',
            'rath_bau_fb' => 'Rath Bau (Facebook)',
        ], $options);
    }

    #[Test]
    public function it_builds_the_payload_from_the_entry(): void
    {
        $entry = $this->entry([
            'social_hub_targets' => [
                ['enabled' => true, 'account' => 'rath_bau', 'type' => 'image', 'caption' => 'Nur für Instagram'],
                ['enabled' => false, 'account' => 'rath_bau_fb'],
                ['enabled' => true, 'account' => ['rath_bau_fb2']],
            ],
            'social_hub_images' => ['richtfest.jpg'],
            'social_hub_scheduled_at' => '2026-10-01 09:30',
        ]);

        $payload = app(PostPayloadBuilder::class)->build($entry, true);

        $this->assertSame('Richtfest', $payload['title']);
        $this->assertSame("Richtfest\n\nWir feiern den Rohbau.", $payload['body']);
        $siteUrl = rtrim(Site::default()->absoluteUrl(), '/');

        $this->assertSame($siteUrl.'/news/richtfest', $payload['link']);
        $this->assertTrue(Carbon::parse($payload['scheduled_at'])->eq(Carbon::parse('2026-10-01 09:30', 'Europe/Berlin')));
        $this->assertSame('entry-1', $payload['source_reference']);
        $this->assertSame([['url' => $siteUrl.'/assets/richtfest.jpg', 'alt' => 'Richtfest auf der Baustelle']], $payload['media']);
        $this->assertSame([
            ['account' => 'rath_bau', 'caption' => 'Nur für Instagram', 'type' => 'image'],
            ['account' => 'rath_bau_fb2'],
        ], $payload['targets']);
        $this->assertTrue($payload['submit']);
    }

    #[Test]
    public function the_action_sends_the_post_and_stores_the_status(): void
    {
        Http::fake([
            'hub.test/api/v1/posts' => Http::response(['data' => [
                'id' => 'post-uuid-1',
                'status' => 'pending_approval',
                'status_label' => 'Wartet auf Freigabe',
                'source_reference' => 'entry-1',
                'targets' => [['account' => 'rath_bau', 'platform' => 'instagram', 'type' => 'image', 'status' => 'pending', 'permalink' => null, 'error' => null, 'published_at' => null]],
            ]], 201),
        ]);

        $entry = $this->entry([
            'social_hub_targets' => [['enabled' => true, 'account' => 'rath_bau']],
            'social_hub_text' => 'Eigener Text',
            'social_hub_include_link' => false,
        ]);

        $message = (new SendToSocialHub)->run(collect([$entry]), ['submit' => false]);

        $this->assertSame('An den Social Hub gesendet.', $message);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://hub.test/api/v1/posts'
            && $request['body'] === 'Eigener Text'
            && $request['link'] === null
            && $request['submit'] === false
            && $request['source_reference'] === 'entry-1'
            && $request->hasHeader('Authorization', 'Bearer test-site-key'));

        $fresh = Entry::find('entry-1');
        $this->assertSame('post-uuid-1', $fresh->get('social_hub_post_id'));
        $this->assertSame('pending_approval', $fresh->get('social_hub_status'));
        $this->assertNotNull($fresh->get('social_hub_sent_at'));
    }

    #[Test]
    public function a_conflict_is_reported_in_german(): void
    {
        Http::fake(['hub.test/api/v1/posts' => Http::response(['message' => 'Locked'], 409)]);

        $entry = $this->entry(['social_hub_targets' => [['enabled' => true, 'account' => 'rath_bau']]]);

        $this->expectExceptionMessage('bereits freigegeben oder veröffentlicht');

        (new SendToSocialHub)->run(collect([$entry]), []);
    }

    #[Test]
    public function a_hub_timeout_explains_that_the_post_may_exist(): void
    {
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received');
        });

        $entry = $this->entry(['social_hub_targets' => [['enabled' => true, 'account' => 'rath_bau']]]);

        try {
            (new SendToSocialHub)->run(collect([$entry]), []);
            $this->fail('Exception erwartet');
        } catch (\Exception $exception) {
            $this->assertStringStartsWith('„Richtfest“: Der Hub antwortet nicht rechtzeitig', $exception->getMessage());
            $this->assertStringContainsString('evtl. trotzdem angelegt', $exception->getMessage());
        }

        $this->assertSame(1, $attempts);
        $this->assertNull(Entry::find('entry-1')->get('social_hub_sent_at'));
    }

    #[Test]
    public function without_active_channels_nothing_is_sent(): void
    {
        Http::fake();

        $entry = $this->entry(['social_hub_targets' => [['enabled' => false, 'account' => 'rath_bau']]]);

        try {
            (new SendToSocialHub)->run(collect([$entry]), []);
            $this->fail('Exception erwartet');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('mindestens einen Kanal', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function the_action_is_only_visible_for_entries_with_the_fieldset(): void
    {
        $action = new SendToSocialHub;

        $this->assertTrue($action->visibleTo($this->entry([])));

        $page = Entry::make()->collection('pages')->id('page-1')->slug('seite')->data(['title' => 'Seite']);
        $page->save();

        $this->assertFalse($action->visibleTo($page));
    }

    #[Test]
    public function the_action_requires_the_manage_permission(): void
    {
        $entry = $this->entry([]);
        $action = new SendToSocialHub;

        $editor = User::make()->id('redaktion')->email('redaktion@example.com');
        $this->assertFalse($action->authorize($editor, $entry));

        $admin = User::make()->id('admin')->email('admin@example.com')->makeSuper();
        $this->assertTrue($action->authorize($admin, $entry));
    }

    #[Test]
    public function saving_an_entry_never_sends_anything(): void
    {
        Http::fake();

        $entry = $this->entry(['social_hub_targets' => [['enabled' => true, 'account' => 'rath_bau']]]);
        $entry->set('title', 'Geändert')->save();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function entry(array $data): \Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::make()->collection('news')->blueprint('news')->id('entry-1')->slug('richtfest')->data(array_merge([
            'title' => 'Richtfest',
            'teaser' => 'Wir feiern den Rohbau.',
        ], $data));

        $entry->save();

        return $entry;
    }
}
