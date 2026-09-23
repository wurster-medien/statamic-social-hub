<?php

namespace WursterMedien\SocialHub\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Support\Str;
use WursterMedien\SocialHub\Feeds\FeedItemPresenter;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Tests\TestCase;

class SocialTagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'hub.test/api/v1/accounts' => Http::response(['data' => [
                ['handle' => 'rath_bau', 'platform' => 'instagram', 'username' => 'rath_bau', 'name' => 'Rath Bau', 'status' => 'active'],
                ['handle' => 'zinser', 'platform' => 'instagram', 'username' => 'zinser', 'name' => 'Zinser', 'status' => 'active'],
            ]]),
            'hub.test/api/v1/feeds/rath_bau*' => Http::response($this->hubFeed([
                $this->hubMedia('1', [
                    'media_type' => 'VIDEO',
                    'media_url' => 'https://hub.test/storage/social/1.mp4',
                    'thumbnail_url' => 'https://hub.test/storage/social/1_thumb.jpg',
                    'caption' => "Richtfest in Musterstadt!\nDanke an alle. #richtfest #holzbau",
                ]),
                $this->hubMedia('2', [
                    'media_type' => 'CAROUSEL_ALBUM',
                    'children' => [
                        $this->hubMedia('21', ['caption' => null, 'media_url' => 'https://hub.test/storage/social/21.jpg']),
                        $this->hubMedia('22', ['caption' => null, 'media_url' => 'https://hub.test/storage/social/22.jpg']),
                    ],
                ]),
                $this->hubMedia('3'),
            ])),
            'hub.test/api/v1/feeds/zinser*' => Http::response($this->hubFeed([], 'zinser')),
            'hub.test/api/v1/feeds/unbekannt*' => Http::response(['message' => 'Not found'], 404),
            'hub.test/storage/*' => fn () => Http::response('bytes'),
        ]);
    }

    #[Test]
    public function it_renders_the_feed_with_meta_field_names(): void
    {
        $output = $this->render('{{ social:feed account="rath_bau" limit="2" }}[{{ count }}|{{ id }}|{{ media_type }}|{{ media_url }}|{{ thumbnail_url ?? "-" }}|{{ permalink }}|{{ like_count }}|{{ is_video ? "video" : "bild" }}]{{ /social:feed }}');

        $this->assertSame(
            '[1|1|VIDEO|/social-hub/rath_bau/1.mp4|/social-hub/rath_bau/1_thumb.jpg|https://www.instagram.com/p/1/|12|video]'
            .'[2|2|CAROUSEL_ALBUM|/social-hub/rath_bau/2.jpg|-|https://www.instagram.com/p/2/|12|bild]',
            $output,
        );
    }

    #[Test]
    public function it_adds_alt_text_without_hashtags_and_line_breaks(): void
    {
        $output = $this->render('{{ social:feed account="rath_bau" limit="1" }}{{ alt }}{{ /social:feed }}');

        $this->assertSame('Richtfest in Musterstadt! Danke an alle.', $output);
    }

    #[Test]
    public function alt_text_is_limited_to_125_characters(): void
    {
        $alt = FeedItemPresenter::altText(str_repeat('Sehr langer Text ', 20).'#tag');

        $this->assertLessThanOrEqual(125, mb_strlen($alt));
        $this->assertStringEndsWith('…', $alt);
        $this->assertStringNotContainsString('#', $alt);
    }

    #[Test]
    public function children_are_looped_and_inherit_the_alt_text(): void
    {
        $output = $this->render('{{ social:feed account="rath_bau" limit="2" offset="1" }}{{ if children }}{{ children }}<{{ media_url }}|{{ alt }}>{{ /children }}{{ /if }}{{ /social:feed }}');

        $this->assertSame(
            '</social-hub/rath_bau/21.jpg|Neues Projekt 2 fertig!></social-hub/rath_bau/22.jpg|Neues Projekt 2 fertig!>',
            $output,
        );
    }

    #[Test]
    public function alt_is_safe_inside_attributes_and_caption_html_is_escaped(): void
    {
        Http::fake([
            'hub.test/api/v1/feeds/xss*' => Http::response($this->hubFeed([
                $this->hubMedia('9', [
                    'media_url' => null,
                    'caption' => "Tom's \"Bau\" onerror=alert(1) <script>alert(2)</script> & Co\nZweite Zeile #tag",
                ]),
            ], 'xss')),
        ]);

        $output = $this->render('{{ social:feed account="xss" }}<img alt="{{ alt }}">|{{ alt | entities }}|{{ caption_html }}|{{ caption | entities }}{{ /social:feed }}');

        [$img, $altEntities, $captionHtml, $captionEntities] = explode('|', $output);

        $alt = 'Tom&#039;s &quot;Bau&quot; onerror=alert(1) &lt;script&gt;alert(2)&lt;/script&gt; &amp; Co Zweite Zeile';

        $this->assertSame('<img alt="'.$alt.'">', $img);
        // Kein doppeltes Kodieren, wenn ein Template zusätzlich | entities nutzt.
        $this->assertSame($alt, $altEntities);
        $this->assertStringNotContainsString('<script>', $captionHtml);
        $this->assertStringContainsString('&lt;script&gt;', $captionHtml);
        $this->assertMatchesRegularExpression('/&amp; Co<br>\s+Zweite Zeile #tag/', $captionHtml);
        $this->assertStringNotContainsString('<script>', $captionEntities);
    }

    #[Test]
    public function caption_stays_raw_and_caption_html_is_offered(): void
    {
        $item = app(FeedItemPresenter::class)->present($this->hubMedia('5', [
            'caption' => "A <b>fett</b> & \"zitiert\"\r\nB",
        ]), 'rath_bau');

        $this->assertSame("A <b>fett</b> & \"zitiert\"\r\nB", $item['caption']);
        $this->assertSame("A &lt;b&gt;fett&lt;/b&gt; &amp; &quot;zitiert&quot;<br>\r\nB", $item['caption_html']);
        $this->assertSame('A &lt;b&gt;fett&lt;/b&gt; &amp; &quot;zitiert&quot; B', $item['alt']);
        $this->assertSame('Tom&#039;s', app(FeedItemPresenter::class)->present($this->hubMedia('7', ['caption' => "Tom's"]), 'rath_bau')['alt']);
        $this->assertSame('', app(FeedItemPresenter::class)->present($this->hubMedia('6', ['caption' => null]), 'rath_bau')['caption_html']);
    }

    #[Test]
    public function the_handle_parameter_is_an_alias_for_account(): void
    {
        $output = $this->render('{{ social:feed handle="rath_bau" limit="3" }}{{ id }},{{ /social:feed }}');

        $this->assertSame('1,2,3,', $output);
    }

    #[Test]
    public function the_as_parameter_provides_a_list_variable(): void
    {
        $output = $this->render('{{ social:feed handle="rath_bau" limit="3" as="posts" }}{{ total_results }}:{{ posts limit="2" }}{{ id }};{{ /posts }}{{ /social:feed }}');

        $this->assertSame('3:1;2;', $output);
    }

    #[Test]
    public function an_empty_feed_renders_no_results(): void
    {
        $output = $this->render('{{ social:feed account="zinser" }}{{ if no_results }}Keine Beiträge{{ else }}{{ id }}{{ /if }}{{ /social:feed }}');

        $this->assertSame('Keine Beiträge', $output);
    }

    #[Test]
    public function an_unknown_handle_renders_no_results_instead_of_an_error(): void
    {
        $output = $this->render('{{ social:feed account="unbekannt" }}{{ if no_results }}leer{{ /if }}{{ /social:feed }}');

        $this->assertSame('leer', $output);
    }

    #[Test]
    public function without_account_the_default_account_is_used(): void
    {
        $this->assertSame('1', $this->render('{{ social:feed limit="1" }}{{ id }}{{ /social:feed }}'));

        config(['social-hub.default_account' => 'zinser']);

        $this->assertSame('leer', $this->render('{{ social:feed }}{{ if no_results }}leer{{ /if }}{{ /social:feed }}'));
    }

    #[Test]
    public function date_is_a_carbon_instance(): void
    {
        $output = $this->render('{{ social:feed account="rath_bau" limit="1" }}{{ date format="d.m.Y H:i" }}|{{ timestamp }}{{ /social:feed }}');

        $this->assertSame('20.09.2026 12:15|2026-09-20T10:15:00+00:00', $output);
    }

    #[Test]
    public function a_missing_social_variable_does_not_trigger_the_feed(): void
    {
        // Templates wie zinser-holzbau nutzen {{ social }} … {{ /social }} bzw.
        // {{ social:label }} als Variable. Fehlt sie, darf kein Feed erscheinen.
        $output = $this->render(
            '{{ links }}<{{ social }}X{{ /social }}|{{ social:label }}>{{ /links }}',
            ['links' => [['social' => [['url' => 'u']]], ['title' => 'ohne'], ['social' => null]]],
        );

        $this->assertSame('<X|><|><|>', $output);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/feeds/'));
    }

    #[Test]
    public function a_single_media_item_can_be_rendered(): void
    {
        $output = $this->render('{{ social:media account="rath_bau" id="3" }}{{ id }}|{{ media_url }}{{ /social:media }}');

        $this->assertSame('3|/social-hub/rath_bau/3.jpg', $output);
    }

    #[Test]
    public function accounts_are_listed(): void
    {
        $this->assertSame('rath_bau,zinser,', $this->render('{{ social:accounts }}{{ handle }},{{ /social:accounts }}'));
    }

    #[Test]
    public function glide_can_process_the_mirrored_path(): void
    {
        // Glide sucht Pfade, die mit "/" beginnen, unter public/. Ein relativer
        // Pfad (statt einer Hub-URL) ist deshalb die Voraussetzung.
        $item = $this->render('{{ social:feed account="rath_bau" limit="1" offset="2" }}{{ media_url }}{{ /social:feed }}');

        $this->assertStringStartsWith('/social-hub/', $item);
        $this->assertTrue(Str::isUrl($item));
        $this->assertFalse(str_starts_with($item, 'http://') || str_starts_with($item, 'https://'));
    }

    #[Test]
    public function the_stale_feed_is_rendered_when_the_hub_fails(): void
    {
        app(StateStore::class)->putFeed('ausfall', [
            'handle' => 'ausfall',
            'data' => [$this->hubMedia('77', ['media_url' => '/social-hub/ausfall/77.jpg'])],
            'meta' => [],
            'fetched_at' => Carbon::now()->subDay()->toIso8601String(),
        ]);

        Http::fake(['hub.test/api/v1/feeds/ausfall*' => Http::response('', 503)]);

        $this->assertSame('77', $this->render('{{ social:feed account="ausfall" }}{{ id }}{{ /social:feed }}'));
    }

    /**
     * Rendert das Template wie eine echte View-Datei (vertrauenswürdiger
     * Kontext; Antlers::parse() behandelt Text in Statamic 6 als Nutzerinhalt).
     */
    /**
     * @param  array<string, mixed>  $data
     */
    protected function render(string $template, array $data = []): string
    {
        $directory = self::tmpPath('views');
        $name = 'social_'.md5($template);

        $this->app['files']->ensureDirectoryExists($directory);
        $this->app['files']->put("{$directory}/{$name}.antlers.html", $template);

        View::addLocation($directory);

        return trim(view($name, $data)->render());
    }
}
