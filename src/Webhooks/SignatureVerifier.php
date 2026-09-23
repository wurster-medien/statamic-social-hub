<?php

namespace WursterMedien\SocialHub\Webhooks;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Prüft die Signatur von Webhooks des Hubs.
 *
 * Header: X-Social-Hub-Signature: t=<unix>,v1=<hex>
 * mit hex = hmac_sha256("<t>.<rohdaten>", SOCIAL_HUB_WEBHOOK_SECRET).
 * Der Zeitstempel darf höchstens webhook_tolerance Sekunden (Standard 300)
 * abweichen, damit abgefangene Anfragen nicht später wiederholt werden können.
 *
 * Schutz gegen Wiederholung innerhalb dieser Zeit: claim() merkt sich jede
 * gültige Signatur im Cache (Nonce). Der Hub signiert jeden Zustellversuch neu,
 * eine bereits bekannte Signatur ist also immer eine Wiederholung.
 */
class SignatureVerifier
{
    public const HEADER = 'X-Social-Hub-Signature';

    public function verify(string $payload, ?string $header, ?string $secret, ?int $now = null): bool
    {
        return $this->validSignature($payload, $header, $secret, $now) !== null;
    }

    /**
     * Die passende v1-Signatur aus dem Header, oder null, wenn keine gültig ist.
     */
    public function validSignature(string $payload, ?string $header, ?string $secret, ?int $now = null): ?string
    {
        if (blank($secret) || blank($header)) {
            return null;
        }

        $parts = $this->parse((string) $header);

        if ($parts['timestamp'] === null || $parts['signatures'] === []) {
            return null;
        }

        $now ??= time();

        if (abs($now - $parts['timestamp']) > $this->tolerance()) {
            return null;
        }

        $expected = self::sign($payload, $parts['timestamp'], (string) $secret);

        foreach ($parts['signatures'] as $signature) {
            if (hash_equals($expected, $signature)) {
                return $expected;
            }
        }

        return null;
    }

    /**
     * Merkt sich eine gültige Signatur für die Dauer der Toleranz.
     *
     * false = diese Signatur wurde schon einmal angenommen (Wiederholung).
     * Ist der Cache nicht erreichbar, wird der Webhook nicht blockiert.
     */
    public function claim(string $signature): bool
    {
        try {
            return Cache::add(
                'social-hub:webhook-nonce:'.hash('sha256', strtolower($signature)),
                true,
                now()->addSeconds(2 * $this->tolerance() + 60),
            );
        } catch (Throwable) {
            return true;
        }
    }

    public static function sign(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    public static function header(string $payload, int $timestamp, string $secret): string
    {
        return 't='.$timestamp.',v1='.self::sign($payload, $timestamp, $secret);
    }

    protected function tolerance(): int
    {
        return max(1, (int) config('social-hub.webhook_tolerance', 300));
    }

    /**
     * @return array{timestamp: int|null, signatures: list<string>}
     */
    protected function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        return ['timestamp' => $timestamp, 'signatures' => $signatures];
    }
}
