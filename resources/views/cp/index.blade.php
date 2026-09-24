@extends('statamic::layout')
@section('title', 'Social Hub')

@section('content')
@php
    $badgeOk = 'inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200';
    $badgeBad = 'inline-flex items-center rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200';
    $badgeNeutral = 'inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200';
    $buttonPrimary = 'inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/30';
    $buttonSecondary = 'inline-flex items-center justify-center rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200';
@endphp

<div class="mx-auto max-w-6xl px-4 py-6">
    <header class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Social Hub</h1>

        @if ($canManage && $configured)
            <form method="POST" action="{{ cp_route('social-hub.sync') }}" class="m-0">
                @csrf
                <button type="submit" class="{{ $buttonPrimary }}">
                    Jetzt synchronisieren
                </button>
            </form>
        @endif
    </header>

    @if (session('social_hub_message'))
        <div class="mb-6 rounded-lg border border-l-4 {{ session('social_hub_ok', true) ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-rose-500 bg-rose-50 text-rose-800' }} px-4 py-3 text-sm shadow-sm">
            {{ session('social_hub_message') }}
        </div>
    @endif

    <section class="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-semibold text-slate-900">Verbindung</h2>
        </div>

        <div class="p-5">
            @if (! $configured)
                <p class="mb-0 flex items-center gap-2 text-sm text-slate-600">
                    <span class="{{ $badgeBad }}">nicht verbunden</span>
                    Den Verbindungscode erhalten Sie von Wurster Medien.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm text-slate-700">
                        <tbody>
                            <tr class="border-b border-slate-200 last:border-b-0">
                                <td class="w-52 py-3 pr-4 font-medium text-slate-500">Status</td>
                                <td class="py-3">
                                    @if ($pingError)
                                        <span class="{{ $badgeBad }}">nicht erreichbar</span>
                                        <span class="ml-2 text-slate-600">{{ $pingError }}</span>
                                    @else
                                        <span class="{{ $badgeOk }}">verbunden</span>
                                    @endif
                                </td>
                            </tr>
                            <tr class="border-b border-slate-200 last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-slate-500">Hub</td>
                                <td class="py-3">
                                    <span class="font-mono text-xs text-slate-700">{{ $hubUrl }}</span>
                                    @if (! empty($ping['hub']['name']))
                                        <span class="text-slate-500">· {{ $ping['hub']['name'] }}</span>
                                    @endif
                                    @if (! empty($ping['hub']['api_version']))
                                        <span class="text-slate-500">· API {{ $ping['hub']['api_version'] }}</span>
                                    @endif
                                </td>
                            </tr>
                            @if (! empty($ping['site']))
                                <tr class="border-b border-slate-200 last:border-b-0">
                                    <td class="py-3 pr-4 font-medium text-slate-500">Seite im Hub</td>
                                    <td class="py-3">
                                        {{ $ping['site']['name'] ?? '–' }}
                                        @if (! empty($ping['site']['client']))
                                            ({{ is_array($ping['site']['client']) ? ($ping['site']['client']['name'] ?? '') : $ping['site']['client'] }})
                                        @endif
                                    </td>
                                </tr>
                            @endif
                            <tr class="border-b border-slate-200 last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-slate-500">Letzter Sync (lokal)</td>
                                <td class="py-3">
                                    {{ $lastSyncAt ?? 'noch nie' }}
                                    @if ($lastSyncOk === true)
                                        <span class="{{ $badgeOk }} ml-2">ok</span>
                                    @elseif ($lastSyncOk === false)
                                        <span class="{{ $badgeBad }} ml-2">mit Fehlern</span>
                                    @endif
                                </td>
                            </tr>
                            <tr class="border-b border-slate-200 last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-slate-500">Automatischer Abruf</td>
                                <td class="py-3">
                                    @if ($scheduled)
                                        alle 30 Minuten über den Scheduler (Cron für <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-700">schedule:run</code> nötig)
                                    @else
                                        aus (<code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-700">SOCIAL_HUB_SCHEDULE=false</code>), Abruf nur beim Seitenaufruf
                                    @endif
                                </td>
                            </tr>
                            <tr class="border-b border-slate-200 last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-slate-500">Webhook</td>
                                <td class="py-3">
                                    <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-700">{{ $webhookUrl }}</code>
                                    @if ($webhookConfigured)
                                        <span class="{{ $badgeOk }} ml-2">Secret gesetzt</span>
                                    @else
                                        <span class="{{ $badgeNeutral }} ml-2">kein Secret (nur für Posten nötig)</span>
                                    @endif
                                </td>
                            </tr>
                            <tr class="border-b border-slate-200 last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-slate-500">Addon-Version</td>
                                <td class="py-3">{{ $addonVersion }}</td>
                            </tr>
                            <tr class="border-b border-slate-200 last:border-b-0">
                                <td class="py-3 pr-4 font-medium text-slate-500">Zugangsdaten</td>
                                <td class="py-3">{{ $usesEnvironment ? 'aus der .env der Seite' : 'aus dem Verbindungscode' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($canConnect && ! $usesEnvironment)
                <form method="POST" action="{{ cp_route('social-hub.connect') }}" class="mt-5">
                    @csrf
                    <label for="social-hub-code" class="mb-2 block text-sm font-medium text-slate-700">
                        {{ $configured ? 'Neuen Verbindungscode einfügen' : 'Verbindungscode einfügen' }}
                    </label>
                    <textarea id="social-hub-code" name="code" rows="3" required autocomplete="off" spellcheck="false" placeholder="shc1.…" class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20"></textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <button type="submit" class="{{ $buttonPrimary }}">Verbinden</button>
                        <span class="text-sm text-slate-500">Der Code wird geprüft, verschlüsselt gespeichert und danach werden die Beiträge geladen.</span>
                    </div>
                </form>

                @if ($hasStoredCode)
                    <form method="POST" action="{{ cp_route('social-hub.disconnect') }}" class="mt-4" onsubmit="return confirm('Verbindung zum Social Hub trennen? Die Seite zeigt dann nur noch die zuletzt geladenen Beiträge.');">
                        @csrf
                        <button type="submit" class="{{ $buttonSecondary }}">Verbindung trennen</button>
                    </form>
                @endif
            @elseif (! $configured)
                <p class="mt-4 text-sm text-slate-500">Zum Verbinden ist das Recht „Mit dem Social Hub verbinden“ nötig.</p>
            @endif
        </div>
    </section>

    <section class="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-semibold text-slate-900">Konten</h2>
        </div>

        <div class="p-5">
            @if (count($accounts) === 0)
                <p class="m-0 text-sm text-slate-500">Keine Konten. Im Hub müssen der Seite Konten mit einem Handle zugewiesen sein.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm text-slate-700">
                        <thead>
                            <tr class="border-b border-slate-200">
                                <th class="pb-3 pr-3 text-left font-semibold text-slate-600">Handle (Tag)</th>
                                <th class="pb-3 pr-3 text-left font-semibold text-slate-600">Plattform</th>
                                <th class="pb-3 pr-3 text-left font-semibold text-slate-600">Konto</th>
                                <th class="pb-3 pr-3 text-left font-semibold text-slate-600">Status</th>
                                <th class="pb-3 pr-3 text-left font-semibold text-slate-600">Abruf beim Hub</th>
                                <th class="pb-3 pr-3 text-left font-semibold text-slate-600">Letzter lokaler Sync</th>
                                <th class="pb-3 text-left font-semibold text-slate-600">Medien lokal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                <tr class="border-b border-slate-200 last:border-b-0">
                                    <td class="py-3 pr-3 font-mono text-xs text-slate-700">{{ $account['handle'] }}</td>
                                    <td class="py-3 pr-3">{{ $account['platform'] ?? '–' }}</td>
                                    <td class="py-3 pr-3">
                                        {{ $account['name'] ?? '' }}
                                        @if ($account['username'])
                                            <span class="text-slate-500">&#64;{{ $account['username'] }}</span>
                                        @endif
                                        @if ($account['can_publish'])
                                            <span class="{{ $badgeNeutral }} ml-2">posten möglich</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-3">
                                        <span class="{{ in_array($account['status'], ['active', 'ok', 'connected'], true) ? $badgeOk : ($account['status'] ? $badgeBad : $badgeNeutral) }}">{{ $account['status'] ?? 'unbekannt' }}</span>
                                    </td>
                                    <td class="py-3 pr-3">{{ $account['hub_synced_at'] ?? '–' }}</td>
                                    <td class="py-3 pr-3">{{ $account['local_synced_at'] ?? '–' }}</td>
                                    <td class="py-3">{{ $account['items'] }}</td>
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
                <p class="mt-4 text-sm text-slate-500">
                    Im Template: <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-700">{{ $example }}</code>
                </p>
            @endif
        </div>
    </section>

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-semibold text-slate-900">Letzte Fehler</h2>

            @if ($canManage && count($hubErrors) > 0)
                <form method="POST" action="{{ cp_route('social-hub.sync') }}" class="m-0">
                    @csrf
                    <input type="hidden" name="clear_errors" value="1">
                    <button type="submit" class="{{ $buttonSecondary }}">Liste leeren</button>
                </form>
            @endif
        </div>

        <div class="p-5">
            @if (count($hubErrors) === 0)
                <p class="m-0 text-sm text-slate-500">Keine Fehler.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm text-slate-700">
                        <tbody>
                            @foreach ($hubErrors as $error)
                                <tr class="border-b border-slate-200 last:border-b-0">
                                    <td class="py-3 pr-4 text-slate-500">{{ $error['time'] }}</td>
                                    <td class="py-3 pr-4 font-medium text-slate-600">{{ $error['context'] }}</td>
                                    <td class="py-3">{{ $error['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
</div>
@endsection
