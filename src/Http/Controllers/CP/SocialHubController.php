<?php

namespace WursterMedien\SocialHub\Http\Controllers\CP;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;
use WursterMedien\SocialHub\Accounts\AccountRepository;
use WursterMedien\SocialHub\Hub\HubClient;
use WursterMedien\SocialHub\Hub\HubException;
use WursterMedien\SocialHub\Support\HubConnection;
use WursterMedien\SocialHub\Support\InvalidConnectionCode;
use WursterMedien\SocialHub\Support\StatamicVersion;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Support\SyncStatus;
use WursterMedien\SocialHub\Sync\Synchronizer;

/**
 * Control-Panel-Seite "Social Hub": Verbindung (Verbindungscode einfügen), Konten, Fehler, Sync-Knopf.
 *
 * Bewusst eine Blade-Seite mit @extends('statamic::layout'), in Statamic 6 als NonInertiaPage. Je nach
 * Statamic-Version eine eigene View: cp/v6 nutzt die <ui-…>-Komponenten, cp/v5 die CSS-Klassen von Statamic 5.
 */
class SocialHubController extends CpController
{
    public function index(HubClient $client, AccountRepository $accounts, StateStore $store, SyncStatus $status, HubConnection $connection, StatamicVersion $statamic): View
    {
        $this->authorize('view social hub');

        $configured = $client->isConfigured();
        $ping = null;
        $pingError = null;
        $accountList = [];

        if ($configured) {
            try {
                $ping = $client->ping();
                $status->recordPing($ping);
            } catch (HubException $exception) {
                $pingError = $exception->getMessage();
                $status->recordPing(null, $pingError);
            }

            try {
                $accountList = $accounts->refresh();
            } catch (HubException $exception) {
                $accountList = $accounts->stored();
            }
        }

        $state = $status->all();
        $feeds = $store->feeds();

        $rows = array_map(function (array $account) use ($state, $feeds) {
            $handle = (string) $account['handle'];

            return [
                'handle' => $handle,
                'platform' => $account['platform'] ?? null,
                'username' => $account['username'] ?? null,
                'name' => $account['name'] ?? null,
                'status' => $account['status'] ?? null,
                'can_publish' => (bool) ($account['can_publish'] ?? false),
                'hub_synced_at' => $this->formatDate($account['last_synced_at'] ?? null),
                'local_synced_at' => $this->formatDate($state['accounts'][$handle] ?? null),
                'items' => count($feeds[$handle]['data'] ?? []),
            ];
        }, $accountList);

        return view($statamic->controlPanelView(), [
            'title' => 'Social Hub',
            'configured' => $configured,
            'hubUrl' => $connection->url(),
            'ping' => $ping,
            'pingError' => $pingError,
            'accounts' => $rows,
            'lastSyncAt' => $this->formatDate($state['last_sync_at'] ?? null),
            'lastSyncOk' => $state['last_sync_ok'] ?? null,
            'hubErrors' => array_map(fn (array $error) => [
                'time' => $this->formatDate($error['time'] ?? null),
                'context' => $error['context'] ?? '',
                'message' => $error['message'] ?? '',
            ], $state['errors'] ?? []),
            'addonVersion' => $client->addonVersion(),
            'scheduled' => (bool) config('social-hub.schedule', true),
            'canManage' => auth()->user()?->can('manage social hub') ?? false,
            'webhookUrl' => url(config('statamic.routes.action', '!').'/social-hub/webhook'),
            'webhookConfigured' => filled($connection->webhookSecret()),
            'canConnect' => auth()->user()?->can('connect social hub') ?? false,
            'usesEnvironment' => $connection->usesEnvironment(),
            'hasStoredCode' => $connection->hasStoredCode(),
        ]);
    }

    /**
     * Verbindungscode aus dem Hub einfügen: erst mit einem Ping prüfen, dann verschlüsselt speichern und
     * gleich synchronisieren, damit der Feed sofort da ist.
     */
    public function connect(HubConnection $connection, HubClient $client, Synchronizer $synchronizer): RedirectResponse
    {
        $this->authorize('connect social hub');

        if ($connection->usesEnvironment()) {
            return $this->backWithMessage('Die Zugangsdaten stehen in der .env der Seite und haben Vorrang. Bitte Wurster Medien kontaktieren.', false);
        }

        try {
            $credentials = $connection->parse((string) request()->input('code'));
        } catch (InvalidConnectionCode $exception) {
            return $this->backWithMessage($exception->getMessage(), false);
        }

        try {
            $ping = $client->withCredentials($credentials['url'], $credentials['key'])->ping();
        } catch (HubException $exception) {
            return $this->backWithMessage('Der Hub hat den Verbindungscode nicht angenommen: '.$exception->getMessage(), false);
        }

        $connection->store($credentials);

        $site = is_string($ping['site']['name'] ?? null) ? ' als „'.$ping['site']['name'].'“' : '';

        try {
            $result = $synchronizer->run([], microtime(true) + 25);
            $synced = ' '.$result->summary();
        } catch (Throwable $exception) {
            report($exception);
            $synced = ' Der erste Abruf ist fehlgeschlagen, bitte „Jetzt synchronisieren“ versuchen.';
        }

        return $this->backWithMessage('Mit dem Social Hub verbunden'.$site.'.'.$synced, true);
    }

    public function disconnect(HubConnection $connection): RedirectResponse
    {
        $this->authorize('connect social hub');

        $connection->forget();

        return $this->backWithMessage('Verbindung getrennt. Die Seite zeigt weiter die zuletzt geladenen Beiträge.', true);
    }

    public function sync(Synchronizer $synchronizer, SyncStatus $status): RedirectResponse
    {
        $this->authorize('manage social hub');

        if (request()->boolean('clear_errors')) {
            $status->clearErrors();

            return redirect()->to(cp_route('social-hub.index'))->with('social_hub_message', 'Fehlerliste geleert.');
        }

        try {
            // Im Browser höchstens ca. 25 Sekunden Medien laden, den Rest erledigt der nächste Lauf.
            $result = $synchronizer->run([], microtime(true) + 25);
            $message = $result->summary();
            $ok = $result->successful();
        } catch (Throwable $exception) {
            report($exception);
            $message = 'Synchronisieren fehlgeschlagen: '.class_basename($exception);
            $ok = false;
        }

        return redirect()->to(cp_route('social-hub.index'))
            ->with('social_hub_message', $message)
            ->with('social_hub_ok', $ok);
    }

    protected function backWithMessage(string $message, bool $ok): RedirectResponse
    {
        return redirect()->to(cp_route('social-hub.index'))
            ->with('social_hub_message', $message)
            ->with('social_hub_ok', $ok);
    }

    protected function formatDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone', 'UTC'))->format('d.m.Y H:i');
        } catch (Throwable) {
            return null;
        }
    }
}
