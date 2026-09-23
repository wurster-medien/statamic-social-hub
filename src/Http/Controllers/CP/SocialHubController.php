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
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Support\SyncStatus;
use WursterMedien\SocialHub\Sync\Synchronizer;

/**
 * Control-Panel-Seite "Social Hub": Verbindung, Konten, Fehler, Sync-Knopf.
 *
 * Bewusst eine Blade-Seite mit @extends('statamic::layout'): Das läuft in
 * Statamic 5 (Vue 2) und Statamic 6 (Inertia, dort als NonInertiaPage).
 */
class SocialHubController extends CpController
{
    public function index(HubClient $client, AccountRepository $accounts, StateStore $store, SyncStatus $status): View
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

        return view('social-hub::cp.index', [
            'title' => 'Social Hub',
            'configured' => $configured,
            'hubUrl' => config('social-hub.url'),
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
            'webhookConfigured' => filled(config('social-hub.webhook_secret')),
        ]);
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
