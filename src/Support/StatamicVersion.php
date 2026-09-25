<?php

namespace WursterMedien\SocialHub\Support;

use Composer\InstalledVersions;
use Throwable;

/**
 * Hauptversion des installierten Statamic. Das Control Panel von Statamic 5 (Vue 2, eigene CSS-Klassen wie
 * card, btn, data-table) und das von Statamic 6 (<ui-…>-Komponenten) brauchen jeweils eine eigene View.
 */
class StatamicVersion
{
    public function major(): int
    {
        try {
            $version = InstalledVersions::getVersion('statamic/cms');
        } catch (Throwable) {
            $version = null;
        }

        // "5.73.20.0" → 5, "6.x-dev" → "6.9999999.9999999.9999999-dev" → 6. Unbekannt (z. B. "dev-master")
        // → die neueste unterstützte Version.
        if (is_string($version) && preg_match('/^v?(\d+)\./', $version, $matches)) {
            return (int) $matches[1];
        }

        return 6;
    }

    public function controlPanelView(): string
    {
        return $this->major() >= 6 ? 'social-hub::cp.v6.index' : 'social-hub::cp.v5.index';
    }
}
