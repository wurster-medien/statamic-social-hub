<?php

namespace WursterMedien\SocialHub\Hub;

use RuntimeException;

/**
 * Basis aller Fehler bei der Kommunikation mit dem Social Hub.
 *
 * Meldungen enthalten nie den API-Key oder Header, nur Methode, Pfad und Status.
 */
class HubException extends RuntimeException
{
    //
}
