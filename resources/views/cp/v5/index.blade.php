@extends('statamic::layout')
@section('title', 'Social Hub')

{{--
    Ansicht für Statamic 5 (Statamic 6: cp/v6/index.blade.php). Beide Views bekommen dieselben Daten.
    - Nur CSS-Klassen, die das Control Panel von Statamic 5 selbst mitbringt (card, btn, data-table,
      little-dot, input-text …), sonst Inline-Styles.
    - Statamic 5 kompiliert den Inhalt als Vue-Template. v-pre am äußeren Element verhindert, dass {{ … }}
      in Texten vom Hub von Vue ausgewertet werden. Vue-Komponenten gibt es hier deshalb keine.
--}}

@section('content')
@php
    $dot = fn ($status) => in_array($status, ['active', 'ok', 'connected'], true) ? 'bg-green-600' : ($status ? 'bg-red-500' : 'bg-gray-400');
    $muted = 'text-gray dark:text-dark-150';
@endphp

<div v-pre>
    <header class="flex flex-wrap items-center justify-between gap-2 mb-6">
        <h1>Social Hub</h1>

        @if ($canManage && $configured)
            <form method="POST" action="{{ cp_route('social-hub.sync') }}" class="m-0">
                @csrf
                <button type="submit" class="btn-primary">Jetzt synchronisieren</button>
            </form>
        @endif
    </header>

    @if (session('social_hub_message'))
        <div class="card flex items-center mb-6 text-sm">
            <span class="little-dot {{ session('social_hub_ok', true) ? 'bg-green-600' : 'bg-red-500' }} rtl:ml-2 ltr:mr-2"></span>
            <span>{{ session('social_hub_message') }}</span>
        </div>
    @endif

    {{-- Verbindung --}}
    <h2 class="mb-2 font-bold text-lg">Verbindung</h2>
    <div class="card p-0 mb-8">
        <table class="data-table">
            <tr>
                <th class="rtl:pr-4 ltr:pl-4 py-2 w-64">Status</th>
                <td>
                    @if (! $configured)
                        <span class="little-dot bg-red-500 rtl:ml-2 ltr:mr-2"></span>nicht verbunden
                        <span class="{{ $muted }} text-sm rtl:mr-2 ltr:ml-2">Den Verbindungscode erhalten Sie von Wurster Medien.</span>
                    @elseif ($pingError)
                        <span class="little-dot bg-red-500 rtl:ml-2 ltr:mr-2"></span>nicht erreichbar
                        <div class="text-red-500 text-sm mt-1">{{ $pingError }}</div>
                    @else
                        <span class="little-dot bg-green-600 rtl:ml-2 ltr:mr-2"></span>verbunden
                    @endif
                </td>
            </tr>

            @if ($configured)
                <tr>
                    <th class="rtl:pr-4 ltr:pl-4 py-2 w-64">Hub</th>
                    <td>
                        <code>{{ $hubUrl }}</code>
                        @if (! empty($ping['hub']['name']))
                            <span class="{{ $muted }}">· {{ $ping['hub']['name'] }}</span>
                        @endif
                        @if (! empty($ping['hub']['api_version']))
                            <span class="{{ $muted }}">· API {{ $ping['hub']['api_version'] }}</span>
                        @endif
                    </td>
                </tr>

                @if (! empty($ping['site']))
                    <tr>
                        <th class="rtl:pr-4 ltr:pl-4 py-2 w-64">Seite im Hub</th>
                        <td>
                            {{ $ping['site']['name'] ?? '–' }}
                            @if (! empty($ping['site']['client']))
                                <span class="{{ $muted }}">({{ is_array($ping['site']['client']) ? ($ping['site']['client']['name'] ?? '') : $ping['site']['client'] }})</span>
                            @endif
                        </td>
                    </tr>
                @endif

                <tr>
                    <th class="rtl:pr-4 ltr:pl-4 py-2 w-64">Letzter Sync (lokal)</th>
                    <td>
                        {{ $lastSyncAt ?? 'noch nie' }}
                        @if ($lastSyncOk === true)
                            <span class="badge-pill-sm text-xs rtl:mr-2 ltr:ml-2"><span class="little-dot bg-green-600 rtl:ml-1 ltr:mr-1"></span>ok</span>
                        @elseif ($lastSyncOk === false)
                            <span class="badge-pill-sm text-xs rtl:mr-2 ltr:ml-2"><span class="little-dot bg-red-500 rtl:ml-1 ltr:mr-1"></span>mit Fehlern</span>
                        @endif
                    </td>
                </tr>

                <tr>
                    <th class="rtl:pr-4 ltr:pl-4 py-2 w-64 align-top">Automatischer Abruf</th>
                    <td>
                        @if ($scheduled)
                            <p class="mb-2">Alle 30 Minuten über den Laravel-Scheduler. Dafür muss auf dem Server jede Minute <code>schedule:run</code> laufen.</p>
                            <p class="font-medium mb-1">Einrichtung in Ploi</p>
                            <ol class="text-sm" style="list-style: decimal; padding-inline-start: 1.25rem;">
                                <li class="mb-1">Server öffnen und links <strong>Cronjobs</strong> wählen.</li>
                                <li class="mb-1">
                                    Als Befehl eintragen:
                                    <input type="text" readonly class="input-text font-mono text-xs mt-1" value="php {{ base_path('artisan') }} schedule:run" onfocus="this.select()">
                                </li>
                                <li class="mb-1">Frequenz <strong>Every minute</strong> (<code>* * * * *</code>), Benutzer <code>ploi</code>, speichern.</li>
                            </ol>
                            <p class="{{ $muted }} text-sm mt-2">
                                Der Cronjob läuft jede Minute, den Abruf alle 30 Minuten steuert das Addon selbst. Nutzt die Seite eine andere PHP-Version als der Server-Standard, <code>php8.x</code> statt <code>php</code> verwenden. Gibt es für diese Seite schon einen Cronjob mit <code>schedule:run</code>, ist nichts weiter zu tun.
                            </p>
                        @else
                            aus, Abruf nur beim Seitenaufruf
                            <span class="{{ $muted }} text-sm">(abgeschaltet über <code>SOCIAL_HUB_SCHEDULE=false</code>)</span>
                        @endif
                    </td>
                </tr>

                <tr>
                    <th class="rtl:pr-4 ltr:pl-4 py-2 w-64 align-top">Webhook</th>
                    <td>
                        <input type="text" readonly class="input-text font-mono text-xs" value="{{ $webhookUrl }}" onfocus="this.select()">
                        <p class="{{ $muted }} text-sm mt-1">{{ $webhookConfigured ? 'Secret gesetzt.' : 'Kein Secret gesetzt (nur zum Posten nötig).' }}</p>
                    </td>
                </tr>

                <tr>
                    <th class="rtl:pr-4 ltr:pl-4 py-2 w-64">Zugangsdaten</th>
                    <td>{{ $usesEnvironment ? 'aus der .env der Seite' : 'aus dem Verbindungscode' }}</td>
                </tr>

                <tr>
                    <th class="rtl:pr-4 ltr:pl-4 py-2 w-64">Addon-Version</th>
                    <td>{{ $addonVersion }}</td>
                </tr>
            @endif
        </table>

        @if ($canConnect && ! $usesEnvironment)
            <div class="p-4 border-t dark:border-dark-900">
                <label for="social-hub-code" class="block font-bold mb-1">{{ $configured ? 'Neuer Verbindungscode' : 'Verbindungscode' }}</label>
                <p class="{{ $muted }} text-sm mb-2">Der Code wird geprüft, verschlüsselt gespeichert und danach werden die Beiträge geladen.</p>

                <form id="social-hub-connect" method="POST" action="{{ cp_route('social-hub.connect') }}" class="m-0">
                    @csrf
                    <textarea id="social-hub-code" name="code" rows="3" required autocomplete="off" spellcheck="false" placeholder="shc1.…" class="input-text font-mono text-xs"></textarea>
                </form>

                <div class="flex flex-wrap items-center gap-2 mt-3">
                    <button type="submit" form="social-hub-connect" class="btn-primary">Verbinden</button>
                    @if ($hasStoredCode)
                        <form method="POST" action="{{ cp_route('social-hub.disconnect') }}" class="m-0" onsubmit="return confirm('Verbindung zum Social Hub trennen? Die Seite zeigt dann nur noch die zuletzt geladenen Beiträge.');">
                            @csrf
                            <button type="submit" class="btn">Verbindung trennen</button>
                        </form>
                    @endif
                </div>
            </div>
        @elseif (! $configured)
            <div class="p-4 border-t dark:border-dark-900 text-sm {{ $muted }}">
                Zum Verbinden ist das Recht „Mit dem Social Hub verbinden“ nötig.
            </div>
        @endif
    </div>

    {{-- Konten --}}
    <h2 class="mb-2 font-bold text-lg">Konten</h2>
    @if (count($accounts) === 0)
        <p class="text-sm {{ $muted }} mb-8">Keine Konten. Im Hub müssen der Seite Konten mit einem Handle zugewiesen sein.</p>
    @else
        <div class="card p-0 overflow-x-auto">
            <table class="data-table">
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
                            <td><code>{{ $account['handle'] }}</code></td>
                            <td>{{ $account['platform'] ?? '–' }}</td>
                            <td>
                                {{ $account['name'] ?? '' }}
                                @if ($account['username'])
                                    <span class="{{ $muted }}">&#64;{{ $account['username'] }}</span>
                                @endif
                                @if ($account['can_publish'])
                                    <span class="badge-pill-sm text-xs rtl:mr-2 ltr:ml-2">posten möglich</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="little-dot {{ $dot($account['status']) }} rtl:ml-2 ltr:mr-2"></span>{{ $account['status'] ?? 'unbekannt' }}
                            </td>
                            <td class="whitespace-nowrap">{{ $account['hub_synced_at'] ?? '–' }}</td>
                            <td class="whitespace-nowrap">{{ $account['local_synced_at'] ?? '–' }}</td>
                            <td>{{ $account['items'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @php
            $open = '{'.'{';
            $close = '}'.'}';
            $example = $open.' social:feed account="'.$accounts[0]['handle'].'" limit="12" '.$close.' … '.$open.' /social:feed '.$close;
        @endphp
        <p class="text-sm {{ $muted }} mt-2 mb-8">Im Template: <code>{{ $example }}</code></p>
    @endif

    {{-- Fehler --}}
    <div class="flex items-center justify-between mb-2">
        <h2 class="font-bold text-lg">Letzte Fehler</h2>
        @if ($canManage && count($hubErrors) > 0)
            <form method="POST" action="{{ cp_route('social-hub.sync') }}" class="m-0">
                @csrf
                <input type="hidden" name="clear_errors" value="1">
                <button type="submit" class="btn btn-sm">Liste leeren</button>
            </form>
        @endif
    </div>

    @if (count($hubErrors) === 0)
        <p class="text-sm {{ $muted }}">Keine Fehler.</p>
    @else
        <div class="card p-0 overflow-x-auto">
            <table class="data-table">
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
                            <td class="whitespace-nowrap">{{ $error['context'] }}</td>
                            <td>{{ $error['message'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
