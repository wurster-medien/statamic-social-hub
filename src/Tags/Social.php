<?php

namespace WursterMedien\SocialHub\Tags;

use Statamic\Tags\Tags;
use Throwable;
use WursterMedien\SocialHub\Accounts\AccountRepository;
use WursterMedien\SocialHub\Feeds\FeedItemPresenter;
use WursterMedien\SocialHub\Feeds\FeedRepository;

/**
 * Antlers-Tag für Feeds aus dem Social Hub.
 *
 *   {{ social:feed account="rath_bau" limit="12" }} … {{ /social:feed }}
 *   {{ social:feed handle="rath_bau" as="posts" }} {{ posts }} … {{ /posts }} {{ /social:feed }}
 *   {{ social:media account="rath_bau" id="1789…" }} … {{ /social:media }}
 *   {{ social:accounts }} {{ handle }} {{ /social:accounts }}
 *
 * Fehler beim Hub landen nie im Template: dann gibt es den letzten guten Feed
 * oder eine leere Liste (no_results).
 *
 * Wichtig: Viele Templates nutzen eine Variable "social" (z. B. Links zu
 * Profilen). Fehlt diese Variable oder ist sie leer, ruft Antlers stattdessen
 * diesen Tag auf ({{ social }}, {{ social:label }}). index() und wildcard()
 * geben deshalb bewusst nichts aus, statt einen Feed zu laden.
 */
class Social extends Tags
{
    protected static $handle = 'social';

    public function __construct(
        protected FeedRepository $feeds,
        protected AccountRepository $accounts,
        protected FeedItemPresenter $presenter,
    ) {}

    /**
     * {{ social }} ohne Methode: gibt nichts aus (siehe Klassenkommentar).
     */
    public function index(): string
    {
        return '';
    }

    /**
     * Unbekannte Methoden wie {{ social:label }}: gibt nichts aus, damit eine
     * fehlende Variable "social" nicht zu einem Fehler oder Hub-Aufruf führt.
     */
    public function wildcard(string $method): string
    {
        return '';
    }

    /**
     * @return array<int|string, mixed>
     */
    public function feed(?string $account = null): array
    {
        try {
            $handle = $account ?? $this->account();

            if ($handle === null) {
                return $this->outputList([]);
            }

            $limit = $this->params->int('limit', (int) config('social-hub.default_limit', 12));
            $offset = $this->params->int('offset', 0);

            $items = array_map(
                fn (array $item) => $this->presenter->present($item, $handle),
                $this->feeds->items($handle, $limit, $offset),
            );

            return $this->outputList($items);
        } catch (Throwable $exception) {
            report($exception);

            return $this->outputList([]);
        }
    }

    /**
     * Ein einzelnes Medium: {{ social:media account="…" id="…" }}.
     *
     * @return array<string, mixed>
     */
    public function media(): array
    {
        try {
            $handle = $this->account();
            $id = $this->params->get('id');

            if ($handle === null || blank($id)) {
                return [];
            }

            $item = $this->feeds->media($handle, (string) $id);

            return $item === null ? [] : $this->presenter->present($item, $handle);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * Konten dieser Seite: {{ social:accounts }} {{ handle }} {{ /social:accounts }}.
     *
     * @return array<int|string, mixed>
     */
    public function accounts(): array
    {
        try {
            $accounts = $this->accounts->all();

            if ($platform = $this->params->get('platform')) {
                $accounts = array_values(array_filter($accounts, fn (array $account) => ($account['platform'] ?? null) === $platform));
            }

            return $this->outputList($accounts);
        } catch (Throwable $exception) {
            report($exception);

            return $this->outputList([]);
        }
    }

    protected function account(): ?string
    {
        $handle = $this->params->get(['account', 'handle']);

        if (filled($handle)) {
            return (string) $handle;
        }

        return $this->accounts->defaultHandle();
    }

    /**
     * Wie Statamics OutputsItems: mit as="posts" gibt es eine Variable statt
     * einer Schleife, dazu total_results und no_results.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int|string, mixed>
     */
    protected function outputList(array $items): array
    {
        if ($as = $this->params->get('as')) {
            return [
                $as => $items,
                'total_results' => count($items),
                'no_results' => $items === [],
            ];
        }

        return $items;
    }
}
