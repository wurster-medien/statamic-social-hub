<?php

namespace WursterMedien\SocialHub\Posts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Throwable;

/**
 * Baut aus einem Eintrag mit dem Fieldset "social_hub" den Body für
 * POST /api/v1/posts.
 *
 * source_reference ist die Entry-ID: erneutes Senden aktualisiert denselben
 * Post im Hub, solange er dort noch nicht veröffentlicht bzw. gesperrt ist.
 */
class PostPayloadBuilder
{
    public const MAX_MEDIA = 10;

    /**
     * @return array{title: string|null, body: string, link: string|null, scheduled_at: string|null, source_reference: string, media: list<array{url: string, alt: string|null}>, targets: list<array{account: string, caption?: string, type?: string}>, submit: bool}
     *
     * @throws InvalidPostException
     */
    public function build(Entry $entry, bool $submit = true): array
    {
        $targets = $this->targets($entry);

        if ($targets === []) {
            throw new InvalidPostException('Bitte im Bereich „Social Media“ mindestens einen Kanal aktivieren.');
        }

        $body = trim((string) $entry->get('social_hub_text')) ?: $this->defaultText($entry);
        $media = $this->media($entry);

        if ($body === '' && $media === []) {
            throw new InvalidPostException('Der Post braucht einen Text oder mindestens ein Bild.');
        }

        return [
            'title' => $this->plainText($entry->get('title')) ?: null,
            'body' => $body,
            'link' => $this->link($entry),
            'scheduled_at' => $this->scheduledAt($entry),
            'source_reference' => (string) $entry->id(),
            'media' => $media,
            'targets' => $targets,
            'submit' => $submit,
        ];
    }

    /**
     * Aktivierte Kanäle aus dem Grid social_hub_targets.
     *
     * @return list<array{account: string, caption?: string, type?: string}>
     */
    public function targets(Entry $entry): array
    {
        $targets = [];

        foreach ((array) $entry->get('social_hub_targets', []) as $row) {
            if (! is_array($row) || ($row['enabled'] ?? true) === false) {
                continue;
            }

            $account = $row['account'] ?? null;

            // Dictionary-Feld mit max_items 1 speichert einen String, sonst eine Liste.
            if (is_array($account)) {
                $account = reset($account) ?: null;
            }

            if (blank($account)) {
                continue;
            }

            $target = ['account' => (string) $account];

            if (filled($caption = trim((string) ($row['caption'] ?? '')))) {
                $target['caption'] = $caption;
            }

            if (filled($row['type'] ?? null)) {
                $target['type'] = (string) $row['type'];
            }

            $targets[$target['account']] = $target;
        }

        return array_values($targets);
    }

    /**
     * Standardtext: Titel und Teaser des Eintrags.
     */
    public function defaultText(Entry $entry): string
    {
        $parts = [$this->plainText($entry->get('title'))];

        foreach ((array) config('social-hub.teaser_fields', []) as $field) {
            $teaser = $this->plainText($this->augmented($entry, (string) $field));

            if ($teaser !== '') {
                $parts[] = $teaser;

                break;
            }
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /**
     * @return list<array{url: string, alt: string|null}>
     *
     * @throws InvalidPostException
     */
    public function media(Entry $entry): array
    {
        $media = [];

        foreach ($this->assets($entry) as $asset) {
            $url = $asset->absoluteUrl();

            if (blank($url)) {
                throw new InvalidPostException("Das Bild „{$asset->basename()}“ liegt in einem Container ohne öffentliche URL und kann nicht gesendet werden.");
            }

            if (! Str::startsWith($url, ['http://', 'https://'])) {
                $url = rtrim((string) $entry->site()->absoluteUrl(), '/').'/'.ltrim($url, '/');
            }

            $alt = $asset->get('alt');

            $media[] = ['url' => $url, 'alt' => filled($alt) ? (string) $alt : null];

            if (count($media) >= self::MAX_MEDIA) {
                break;
            }
        }

        return $media;
    }

    protected function link(Entry $entry): ?string
    {
        if ($entry->get('social_hub_include_link') === false) {
            return null;
        }

        try {
            $url = $entry->absoluteUrl();
        } catch (Throwable) {
            return null;
        }

        return filled($url) && Str::startsWith($url, ['http://', 'https://']) ? $url : null;
    }

    protected function scheduledAt(Entry $entry): ?string
    {
        $value = $entry->get('social_hub_scheduled_at');

        if (blank($value)) {
            return null;
        }

        try {
            // Die augmentierte Fassung berücksichtigt, wie die jeweilige
            // Statamic-Version Datumswerte speichert (Statamic 6: UTC).
            $augmented = $this->augmented($entry, 'social_hub_scheduled_at', raw: true);
            $date = $augmented instanceof \DateTimeInterface
                ? Carbon::instance($augmented)
                : Carbon::parse($value, config('app.timezone', 'UTC'));

            return $date->toIso8601String();
        } catch (Throwable) {
            throw new InvalidPostException('Der Zeitpunkt für den Post ist ungültig.');
        }
    }

    /**
     * @return list<Asset>
     */
    protected function assets(Entry $entry): array
    {
        $value = $this->augmented($entry, 'social_hub_images', raw: true);

        if ($value instanceof Asset) {
            return [$value];
        }

        if (is_object($value) && method_exists($value, 'get') && ! $value instanceof Collection) {
            $value = $value->get();
        }

        return collect($value instanceof Collection ? $value->all() : (is_iterable($value) ? $value : []))
            ->filter(fn ($asset) => $asset instanceof Asset)
            ->values()
            ->all();
    }

    /**
     * Augmentierter Wert eines Felds (z. B. Bard → HTML, Assets → Asset-Objekte).
     */
    protected function augmented(Entry $entry, string $field, bool $raw = false): mixed
    {
        try {
            $value = $entry->augmentedValue($field);
            $value = is_object($value) && method_exists($value, 'value') ? $value->value() : $value;
        } catch (Throwable) {
            $value = $entry->get($field);
        }

        return $raw ? $value : (is_string($value) || $value instanceof \Stringable ? (string) $value : (is_scalar($value) ? (string) $value : ''));
    }

    protected function plainText(mixed $value): string
    {
        if (! is_string($value) && ! $value instanceof \Stringable) {
            return '';
        }

        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
