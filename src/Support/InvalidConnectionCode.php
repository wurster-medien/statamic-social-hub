<?php

namespace WursterMedien\SocialHub\Support;

use InvalidArgumentException;

/**
 * Der eingefügte Verbindungscode ist nicht lesbar. Die Meldungen sind für das Control Panel gedacht.
 */
class InvalidConnectionCode extends InvalidArgumentException
{
    public static function format(): self
    {
        return new self('Das ist kein gültiger Verbindungscode. Bitte den vollständigen Code aus dem Social Hub einfügen (beginnt mit „shc1.“).');
    }

    public static function insecureUrl(): self
    {
        return new self('Der Verbindungscode enthält keine sichere Hub-Adresse (https).');
    }

    public static function missingKey(): self
    {
        return new self('Der Verbindungscode enthält keinen API-Key. Bitte im Social Hub „API-Key erzeugen“ und den neuen Code einfügen.');
    }
}
