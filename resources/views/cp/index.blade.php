@extends('statamic::layout')
@section('title', 'Social Hub')

{{--
    Statamic 6 kompiliert den Inhalt dieser View als Vue-Template. Deshalb:
    - Layout über die <ui-…>-Komponenten des Control Panels (ui.statamic.dev),
      eigene Tailwind-Klassen nur, wenn Statamic sie selbst mitbringt.
    - Texte vom Hub oder aus Konten nur mit v-pre ausgeben, damit enthaltene
      {{ … }} nicht von Vue ausgewertet werden, und nie über text=-Props
      (die setzen innerHTML).
--}}

@section('content')
@php
    $statusColor = fn ($status) => in_array($status, ['active', 'ok', 'connected'], true) ? 'green' : ($status ? 'red' : 'default');
@endphp

<div class="max-w-page mx-auto">
    <ui-header title="Social Hub" icon="share-mega-phone">
        @if ($canManage && $configured)
            <form method="POST" action="{{ cp_route('social-hub.sync') }}" class="m-0">
                @csrf
                <ui-button type="submit" variant="primary" icon="sync" text="Jetzt synchronisieren"></ui-button>
            </form>
        @endif
    </ui-header>

    @if (session('social_hub_message'))
        <ui-alert variant="{{ session('social_hub_ok', true) ? 'success' : 'error' }}" class="mb-8">
            <span v-pre>{{ session('social_hub_message') }}</span>
        </ui-alert>
    @endif

    <ui-panel>
        <ui-panel-header class="flex items-center justify-between min-h-10">
            <ui-heading text="Verbindung"></ui-heading>
            @if (! $configured)
                <ui-badge color="red" pill>nicht verbunden</ui-badge>
            @elseif ($pingError)
                <ui-badge color="red" pill>nicht erreichbar</ui-badge>
            @else
                <ui-badge color="green" pill>verbunden</ui-badge>
            @endif
        </ui-panel-header>

        <ui-card inset class="divide-y divide-gray-200 dark:divide-gray-800">
            @if (! $configured)
                <ui-field inline label="Status" instructions="Den Verbindungscode erhalten Sie von Wurster Medien.">
                    <div class="text-sm text-gray-700 dark:text-gray-300">Diese Seite ist noch nicht mit dem Social Hub verbunden.</div>
                </ui-field>
            @else
                @if ($pingError)
                    <ui-field inline label="Status">
                        <div class="text-sm text-red-700 dark:text-red-400" v-pre>{{ $pingError }}</div>
                    </ui-field>
                @endif

                <ui-field inline label="Hub">
                    <div class="flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300" v-pre>
                        <span class="font-mono text-xs">{{ $hubUrl }}</span>
                        @if (! empty($ping['hub']['name']))
                            <span class="text-gray-500">· {{ $ping['hub']['name'] }}</span>
                        @endif
                        @if (! empty($ping['hub']['api_version']))
                            <span class="text-gray-500">· API {{ $ping['hub']['api_version'] }}</span>
                        @endif
                    </div>
                </ui-field>

                @if (! empty($ping['site']))
                    <ui-field inline label="Seite im Hub">
                        <div class="text-sm text-gray-700 dark:text-gray-300" v-pre>
                            {{ $ping['site']['name'] ?? '–' }}
                            @if (! empty($ping['site']['client']))
                                <span class="text-gray-500">({{ is_array($ping['site']['client']) ? ($ping['site']['client']['name'] ?? '') : $ping['site']['client'] }})</span>
                            @endif
                        </div>
                    </ui-field>
                @endif

                <ui-field inline label="Letzter Sync (lokal)">
                    <div class="flex flex-wrap items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <span>{{ $lastSyncAt ?? 'noch nie' }}</span>
                        @if ($lastSyncOk === true)
                            <ui-badge color="green" size="sm" pill>ok</ui-badge>
                        @elseif ($lastSyncOk === false)
                            <ui-badge color="red" size="sm" pill>mit Fehlern</ui-badge>
                        @endif
                    </div>
                </ui-field>

                @if ($scheduled)
                    <ui-field inline label="Automatischer Abruf" instructions="Alle 30 Minuten über den Laravel-Scheduler. Dafür muss auf dem Server jede Minute `schedule:run` laufen.">
                        <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                            <div class="font-medium text-gray-900 dark:text-gray-200">Einrichtung in Ploi</div>
                            <ol class="ps-5 space-y-2" style="list-style: decimal">
                                <li>Server öffnen und links <strong>Cronjobs</strong> wählen.</li>
                                <li>
                                    Als Befehl eintragen:
                                    <ui-input read-only copyable model-value="php {{ base_path('artisan') }} schedule:run" class="font-mono mt-2"></ui-input>
                                </li>
                                <li>Frequenz <strong>Every minute</strong> (<code class="rounded-sm bg-gray-600/10 px-1 py-0.5 text-xs">* * * * *</code>), Benutzer <code class="rounded-sm bg-gray-600/10 px-1 py-0.5 text-xs">ploi</code>, speichern.</li>
                            </ol>
                            <ui-description>
                                Der Cronjob läuft jede Minute, den Abruf alle 30 Minuten steuert das Addon selbst. Nutzt die Seite eine andere PHP-Version als der Server-Standard, <code>php8.x</code> statt <code>php</code> verwenden. Gibt es für diese Seite schon einen Cronjob mit <code>schedule:run</code>, ist nichts weiter zu tun.
                            </ui-description>
                        </div>
                    </ui-field>
                @else
                    <ui-field inline label="Automatischer Abruf" instructions="Abgeschaltet über `SOCIAL_HUB_SCHEDULE=false`.">
                        <div class="text-sm text-gray-700 dark:text-gray-300">aus, Abruf nur beim Seitenaufruf</div>
                    </ui-field>
                @endif

                <ui-field inline label="Webhook" instructions="{{ $webhookConfigured ? 'Secret gesetzt.' : 'Kein Secret gesetzt (nur zum Posten nötig).' }}">
                    <ui-input read-only copyable model-value="{{ $webhookUrl }}" class="font-mono"></ui-input>
                </ui-field>

                <ui-field inline label="Zugangsdaten">
                    <div class="text-sm text-gray-700 dark:text-gray-300">{{ $usesEnvironment ? 'aus der .env der Seite' : 'aus dem Verbindungscode' }}</div>
                </ui-field>

                <ui-field inline label="Addon-Version">
                    <div class="text-sm text-gray-700 dark:text-gray-300">{{ $addonVersion }}</div>
                </ui-field>
            @endif

            @if ($canConnect && ! $usesEnvironment)
                <ui-field
                    inline
                    id="social-hub-code"
                    label="{{ $configured ? 'Neuer Verbindungscode' : 'Verbindungscode' }}"
                    instructions="Der Code wird geprüft, verschlüsselt gespeichert und danach werden die Beiträge geladen."
                >
                    <form id="social-hub-connect" method="POST" action="{{ cp_route('social-hub.connect') }}" class="m-0">
                        @csrf
                        <ui-textarea id="social-hub-code" name="code" rows="3" autocomplete="off" spellcheck="false" placeholder="shc1.…" class="font-mono"></ui-textarea>
                    </form>
                    <div class="flex flex-wrap items-center gap-2 mt-3">
                        <ui-button type="submit" form="social-hub-connect" variant="primary" text="Verbinden"></ui-button>
                        @if ($hasStoredCode)
                            <form method="POST" action="{{ cp_route('social-hub.disconnect') }}" class="m-0" onsubmit="return confirm('Verbindung zum Social Hub trennen? Die Seite zeigt dann nur noch die zuletzt geladenen Beiträge.');">
                                @csrf
                                <ui-button type="submit" variant="ghost" icon="x" text="Verbindung trennen"></ui-button>
                            </form>
                        @endif
                    </div>
                </ui-field>
            @elseif (! $configured)
                <ui-field inline label="Verbindungscode">
                    <div class="text-sm text-gray-700 dark:text-gray-300">Zum Verbinden ist das Recht „Mit dem Social Hub verbinden“ nötig.</div>
                </ui-field>
            @endif
        </ui-card>
    </ui-panel>

    <ui-panel>
        <ui-panel-header class="flex items-center justify-between min-h-10">
            <ui-heading text="Konten"></ui-heading>
        </ui-panel-header>

        @if (count($accounts) === 0)
            <ui-card>
                <ui-description>Keine Konten. Im Hub müssen der Seite Konten mit einem Handle zugewiesen sein.</ui-description>
            </ui-card>
        @else
            <ui-card inset variant="flat" class="overflow-x-auto">
                <table class="data-table data-table--contained">
                    <thead>
                        <tr>
                            <th>Handle (Tag)</th>
                            <th>Plattform</th>
                            <th>Konto</th>
                            <th>Status</th>
                            <th>Abruf beim Hub</th>
                            <th>Letzter lokaler Sync</th>
                            <th>Medien lokal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            <tr>
                                <td><span class="font-mono text-xs text-gray-900 dark:text-gray-200" v-pre>{{ $account['handle'] }}</span></td>
                                <td v-pre>{{ $account['platform'] ?? '–' }}</td>
                                <td>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-gray-900 dark:text-gray-200" v-pre>{{ $account['name'] ?? '' }}</span>
                                        @if ($account['username'])
                                            <span v-pre>&#64;{{ $account['username'] }}</span>
                                        @endif
                                        @if ($account['can_publish'])
                                            <ui-badge size="sm" pill>posten möglich</ui-badge>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <ui-badge color="{{ $statusColor($account['status']) }}" size="sm" pill><span v-pre>{{ $account['status'] ?? 'unbekannt' }}</span></ui-badge>
                                </td>
                                <td class="whitespace-nowrap">{{ $account['hub_synced_at'] ?? '–' }}</td>
                                <td class="whitespace-nowrap">{{ $account['local_synced_at'] ?? '–' }}</td>
                                <td class="tabular-nums">{{ $account['items'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </ui-card>

            @php
                $open = '{'.'{';
                $close = '}'.'}';
                $example = $open.' social:feed account="'.$accounts[0]['handle'].'" limit="12" '.$close.' … '.$open.' /social:feed '.$close;
            @endphp
            <ui-description class="px-4.5 pt-3 pb-1">
                Im Template: <code v-pre>{{ $example }}</code>
            </ui-description>
        @endif
    </ui-panel>

    <ui-panel>
        <ui-panel-header class="flex items-center justify-between min-h-10">
            <ui-heading text="Letzte Fehler"></ui-heading>
            @if ($canManage && count($hubErrors) > 0)
                <form method="POST" action="{{ cp_route('social-hub.sync') }}" class="m-0">
                    @csrf
                    <input type="hidden" name="clear_errors" value="1">
                    <ui-button type="submit" size="sm" text="Liste leeren"></ui-button>
                </form>
            @endif
        </ui-panel-header>

        @if (count($hubErrors) === 0)
            <ui-card>
                <ui-description>Keine Fehler.</ui-description>
            </ui-card>
        @else
            <ui-card inset variant="flat" class="overflow-x-auto">
                <table class="data-table data-table--contained">
                    <thead>
                        <tr>
                            <th>Zeit</th>
                            <th>Bereich</th>
                            <th>Meldung</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hubErrors as $error)
                            <tr>
                                <td class="whitespace-nowrap">{{ $error['time'] }}</td>
                                <td class="whitespace-nowrap text-gray-900 dark:text-gray-200" v-pre>{{ $error['context'] }}</td>
                                <td v-pre>{{ $error['message'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </ui-card>
        @endif
    </ui-panel>
</div>
@endsection
