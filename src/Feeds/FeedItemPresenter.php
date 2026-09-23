<?php

namespace WursterMedien\SocialHub\Feeds;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Bereitet ein Medium für Templates auf.
 *
 * Die Feldnamen des Hubs (wie bei der Meta-API) bleiben unverändert. Dazu
 * kommen: alt, is_video, date (Carbon), account und extension.
 */
class FeedItemPresenter
{
    public const ALT_LENGTH = 125;

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function present(array $item, string $account, ?string $parentAlt = null): array
    {
        $caption = is_string($item['caption'] ?? null) ? $item['caption'] : null;
        $alt = self::altText($caption);

        if ($alt === '' && $parentAlt !== null) {
            $alt = $parentAlt;
        }

        $item['alt'] = $alt;
        $item['is_video'] = ($item['media_type'] ?? null) === 'VIDEO' || filled($item['thumbnail_url'] ?? null);
        $item['date'] = $this->date($item['timestamp'] ?? null);
        $item['account'] = $account;
        $item['extension'] = $this->extension($item['media_url'] ?? null);

        $children = is_array($item['children'] ?? null) ? $item['children'] : [];

        $item['children'] = array_values(array_map(
            fn (array $child) => $this->present($child, $account, $alt),
            array_filter($children, 'is_array'),
        ));

        return $item;
    }

    /**
     * Alt-Text aus der Caption: ohne Hashtags und Zeilenumbrüche, höchstens
     * 125 Zeichen (übliche Empfehlung für Screenreader).
     */
    public static function altText(?string $caption): string
    {
        if ($caption === null || $caption === '') {
            return '';
        }

        $text = preg_replace('/(^|\s)#[\p{L}\p{N}_]+/u', ' ', $caption) ?? $caption;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B-–|·•");

        if (mb_strlen($text) <= self::ALT_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::ALT_LENGTH - 1);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > self::ALT_LENGTH * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, ' ,;:.-').'…';
    }

    protected function date(mixed $timestamp): ?Carbon
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp)->setTimezone(config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    protected function extension(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);

        return $extension === '' ? null : strtolower($extension);
    }
}
