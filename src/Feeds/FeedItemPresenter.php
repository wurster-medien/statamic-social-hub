<?php

namespace WursterMedien\SocialHub\Feeds;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Bereitet ein Medium für Templates auf.
 *
 * Die Feldnamen des Hubs (wie bei der Meta-API) bleiben unverändert. Dazu
 * kommen: alt, caption_html, is_video, date (Carbon), account und extension.
 *
 * Sicherheit: Antlers escaped Variablen nicht automatisch, die Texte stammen
 * aber aus fremden Social-Media-Konten. alt und caption_html sind deshalb
 * bereits HTML-escaped (alt passt gefahrlos in alt="…", caption_html in
 * Elementinhalte). caption bleibt roh und muss im Template mit
 * {{ caption | entities }} ausgegeben werden.
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

        $item['alt'] = self::escape($alt);
        $item['caption_html'] = self::captionHtml($caption);
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

    /**
     * Caption für Elementinhalte: HTML-escaped, Zeilenumbrüche als <br>.
     */
    public static function captionHtml(?string $caption): string
    {
        if ($caption === null || $caption === '') {
            return '';
        }

        return nl2br(self::escape($caption), false);
    }

    /**
     * HTML-Entities für & < > " und ', sicher in Attributen und Elementinhalten.
     *
     * Vorhandene Entities werden nicht doppelt kodiert, damit auch ein
     * zusätzliches {{ alt | entities }} im Template nichts verdoppelt.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
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
