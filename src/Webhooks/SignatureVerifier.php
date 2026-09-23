<?php

namespace WursterMedien\SocialHub\Webhooks;

/**
 * Prüft die Signatur von Webhooks des Hubs.
 *
 * Header: X-Social-Hub-Signature: t=<unix>,v1=<hex>
 * mit hex = hmac_sha256("<t>.<rohdaten>", SOCIAL_HUB_WEBHOOK_SECRET).
 * Der Zeitstempel darf höchstens webhook_tolerance Sekunden (Standard 300)
 * abweichen, damit abgefangene Anfragen nicht wiederholt werden können.
 */
class SignatureVerifier
{
    public const HEADER = 'X-Social-Hub-Signature';

    public function verify(string $payload, ?string $header, ?string $secret, ?int $now = null): bool
    {
        if (blank($secret) || blank($header)) {
            return false;
        }

        $parts = $this->parse((string) $header);

        if ($parts['timestamp'] === null || $parts['signatures'] === []) {
            return false;
        }

        $now ??= time();
        $tolerance = (int) config('social-hub.webhook_tolerance', 300);

        if (abs($now - $parts['timestamp']) > $tolerance) {
            return false;
        }

        $expected = self::sign($payload, $parts['timestamp'], (string) $secret);

        foreach ($parts['signatures'] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $payload, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    public static function header(string $payload, int $timestamp, string $secret): string
    {
        return 't='.$timestamp.',v1='.self::sign($payload, $timestamp, $secret);
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
