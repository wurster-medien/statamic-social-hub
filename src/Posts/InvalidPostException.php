<?php

namespace WursterMedien\SocialHub\Posts;

use RuntimeException;

/**
 * Der Eintrag kann so nicht an den Hub gesendet werden (Meldung für Redakteure).
 */
class InvalidPostException extends RuntimeException
{
    //
}
