<?php

namespace WursterMedien\SocialHub\Dictionaries;

use Statamic\Dictionaries\BasicDictionary;
use Throwable;
use WursterMedien\SocialHub\Accounts\AccountRepository;

/**
 * Auswahlliste der Konten, auf die diese Seite über den Hub posten darf.
 *
 * Quelle: GET /api/v1/accounts (can_publish), bei Hub-Ausfall die zuletzt
 * gespeicherte Liste, ergänzt um config('social-hub.publish_accounts').
 */
class SocialHubAccounts extends BasicDictionary
{
    protected static $handle = 'social_hub_accounts';

    protected array $searchable = ['value', 'label'];

    public static function title()
    {
        return 'Social-Hub-Konten';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function getItems(): array
    {
        $items = [];

        try {
            $accounts = app(AccountRepository::class)->all();
        } catch (Throwable) {
            $accounts = [];
        }

        foreach ($accounts as $account) {
            if (array_key_exists('can_publish', $account) && ! $account['can_publish']) {
                continue;
            }

            $handle = (string) $account['handle'];
            $platform = match ($account['platform'] ?? null) {
                'instagram' => 'Instagram',
                'facebook' => 'Facebook',
                'tiktok' => 'TikTok',
                default => (string) ($account['platform'] ?? ''),
            };

            $name = $account['name'] ?? $account['username'] ?? $handle;
            $items[$handle] = ['value' => $handle, 'label' => $platform !== '' ? "{$name} ({$platform})" : (string) $name];
        }

        foreach ((array) config('social-hub.publish_accounts', []) as $handle => $label) {
            $value = is_string($handle) ? $handle : (string) $label;
            $items[$value] ??= ['value' => $value, 'label' => (string) $label];
        }

        return array_values($items);
    }
}
