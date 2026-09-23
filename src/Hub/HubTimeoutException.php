<?php

namespace WursterMedien\SocialHub\Hub;

use Throwable;

/**
 * Der Hub hat die Anfrage angenommen, aber nicht innerhalb des Timeouts
 * geantwortet. Beim Anlegen eines Posts kann er trotzdem angelegt worden sein.
 */
class HubTimeoutException extends HubConnectionException
{
    public static function postMayExist(?Throwable $previous = null): self
    {
        return new self(
            'Der Hub antwortet nicht rechtzeitig – der Post wurde evtl. trotzdem angelegt. '
            .'Bitte den Status in ein paar Minuten über „An Social Hub senden“ erneut prüfen '
            .'(derselbe Post wird dabei nur aktualisiert, nicht doppelt angelegt).',
            0,
            $previous,
        );
    }
}
