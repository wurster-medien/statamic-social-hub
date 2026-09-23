<?php

namespace WursterMedien\SocialHub\Hub;

class HubNotConfiguredException extends HubException
{
    public static function missing(): self
    {
        return new self('Social Hub ist nicht konfiguriert (SOCIAL_HUB_URL und SOCIAL_HUB_KEY in der .env setzen).');
    }
}
