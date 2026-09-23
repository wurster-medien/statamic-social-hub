<?php

namespace WursterMedien\SocialHub\Posts;

use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * Schreibt den Stand eines Hub-Posts in den Eintrag zurück.
 *
 * Felder (siehe Fieldset social_hub): social_hub_post_id, social_hub_status,
 * social_hub_status_label, social_hub_permalinks, social_hub_error,
 * social_hub_published_at, social_hub_synced_at. Gespeichert wird mit
 * saveQuietly(), damit keine Events (und damit kein erneutes Senden,
 * Static-Caching-Invalidierung o. Ä.) ausgelöst werden.
 */
class PostStatusWriter
{
    /**
     * Sucht den Eintrag zum Post und aktualisiert ihn.
     *
     * @param  array<string, mixed>  $post
     */
    public function apply(array $post): ?EntryContract
    {
        $entry = $this->findEntry($post);

        if ($entry === null) {
            return null;
        }

        $this->write($entry, $post);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    public function findEntry(array $post): ?EntryContract
    {
        $postId = (string) ($post['id'] ?? '');

        if ($postId !== '') {
            $entry = Entry::query()->where('social_hub_post_id', $postId)->first();

            if ($entry !== null) {
                return $entry;
            }
        }

        $reference = (string) ($post['source_reference'] ?? '');

        if ($reference === '') {
            return null;
        }

        $entry = Entry::find($reference);

        if ($entry === null) {
            return null;
        }

        // Nur übernehmen, wenn der Eintrag noch keinem anderen Post gehört.
        $current = (string) $entry->get('social_hub_post_id');

        return $current === '' || $current === $postId ? $entry : null;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    public function write(EntryContract $entry, array $post): void
    {
        $targets = array_values(array_filter((array) ($post['targets'] ?? []), 'is_array'));

        $errors = array_values(array_filter(array_map(
            fn (array $target) => filled($target['error'] ?? null) ? ($target['account'] ?? '?').': '.$target['error'] : null,
            $targets,
        )));

        $entry->set('social_hub_post_id', (string) ($post['id'] ?? $entry->get('social_hub_post_id')));
        $entry->set('social_hub_status', $post['status'] ?? null);
        $entry->set('social_hub_status_label', $post['status_label'] ?? null);
        $entry->set('social_hub_published_at', $post['published_at'] ?? null);
        $entry->set('social_hub_error', $errors === [] ? null : implode("\n", $errors));
        $entry->set('social_hub_synced_at', Carbon::now()->format('Y-m-d H:i'));
        $entry->set('social_hub_permalinks', array_map(fn (array $target) => [
            'account' => $target['account'] ?? null,
            'platform' => $target['platform'] ?? null,
            'status' => $target['status'] ?? null,
            'permalink' => $target['permalink'] ?? null,
            'published_at' => $target['published_at'] ?? null,
        ], $targets));

        $entry->saveQuietly();
    }
}
