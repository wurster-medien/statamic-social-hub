<?php

namespace WursterMedien\SocialHub\Hub;

use Throwable;

class HubConnectionException extends HubException
{
    public static function for(string $method, string $path, ?Throwable $previous = null): self
    {
        return new self("Social Hub nicht erreichbar ({$method} {$path}).", 0, $previous);
    }
}
