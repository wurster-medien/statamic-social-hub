<?php

namespace WursterMedien\SocialHub\Hub;

use Throwable;

class HubConnectionException extends HubException
{
    public static function for(string $method, string $path, ?Throwable $previous = null): self
    {
        return new self("Social Hub nicht erreichbar ({$method} {$path}).", 0, $previous);
    }

    /**
     * true, wenn die Verbindung stand, die Antwort aber zu lange dauerte
     * (cURL-Fehler 28 "Operation timed out"). Scheitert schon der
     * Verbindungsaufbau, hat der Hub die Anfrage nie erhalten.
     */
    public function isResponseTimeout(): bool
    {
        $message = (string) $this->getPrevious()?->getMessage();

        if (preg_match('/connection timed out|resolving timed out|failed to connect|could not resolve|connection refused/i', $message) === 1) {
            return false;
        }

        return str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false;
    }
}
