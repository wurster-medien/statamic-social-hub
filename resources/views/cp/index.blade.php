@extends('statamic::layout')
@section('title', 'Social Hub')

@section('content')
@php
    $box = 'background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:8px;padding:16px 20px;margin-bottom:20px;color:#1f2937;';
    $th = 'text-align:left;padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.1);font-weight:600;font-size:13px;';
    $td = 'padding:6px 8px;border-bottom:1px solid rgba(0,0,0,.06);font-size:13px;vertical-align:top;';
    $ok = 'display:inline-block;padding:1px 8px;border-radius:9px;font-size:12px;background:#dcfce7;color:#166534;';
    $bad = 'display:inline-block;padding:1px 8px;border-radius:9px;font-size:12px;background:#fee2e2;color:#991b1b;';
    $neutral = 'display:inline-block;padding:1px 8px;border-radius:9px;font-size:12px;background:#f3f4f6;color:#374151;';
    $button = 'display:inline-block;padding:7px 14px;border-radius:6px;border:0;background:#2563eb;color:#fff;font-size:14px;cursor:pointer;';
    $input = 'width:100%;padding:8px 10px;border:1px solid rgba(0,0,0,.2);border-radius:6px;font-family:monospace;font-size:13px;box-sizing:border-box;';
    $buttonSecondary = 'display:inline-block;padding:5px 10px;border-radius:6px;border:1px solid rgba(0,0,0,.15);background:#fff;color:#374151;font-size:13px;cursor:pointer;';
@endphp

<div v-pre style="max-width:1100px;margin:0 auto;padding:8px 0 40px;">

    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
        <h1 style="font-size:24px;font-weight:700;margin:0;">Social Hub</h1>

        @if ($canManage && $configured)
            <form method="POST" action="{{ cp_route('social-hub.sync') }}" style="margin:0;">
                @csrf
                <button type="submit" style="{{ $button }}">Jetzt synchronisieren</button>
            </form>
        @endif
    </div>

    @if (session('social_hub_message'))
        <div style="{{ $box }}border-left:4px solid {{ session('social_hub_ok', true) ? '#16a34a' : '#dc2626' }};">
            {{ session('social_hub_message') }}
        </div>
    @endif

    {{-- Verbindung --}}
    <div style="{{ $box }}">
        <h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Verbindung</h2>

        @if (! $configured)
            <p style="margin:0 0 12px;">
                <span style="{{ $bad }}">nicht verbunden</span>
                Den Verbindungscode erhalten Sie von Wurster Medien.
            </p>
        @else
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="{{ $td }}width:220px;">Status</td>
                    <td style="{{ $td }}">
                        @if ($pingError)
                            <span style="{{ $bad }}">nicht erreichbar</span> {{ $pingError }}
                        @else
                            <span style="{{ $ok }}">verbunden</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="{{ $td }}">Hub</td>
                    <td style="{{ $td }}">
                        {{ $hubUrl }}
                        @if (! empty($ping['hub']['name']))
                            · {{ $ping['hub']['name'] }}
                        @endif
                        @if (! empty($ping['hub']['api_version']))
                            · API {{ $ping['hub']['api_version'] }}
                        @endif
                    </td>
                </tr>
                @if (! empty($ping['site']))
                    <tr>
                        <td style="{{ $td }}">Seite im Hub</td>
                        <td style="{{ $td }}">
                            {{ $ping['site']['name'] ?? '–' }}
                            @if (! empty($ping['site']['client']))
                                ({{ is_array($ping['site']['client']) ? ($ping['site']['client']['name'] ?? '') : $ping['site']['client'] }})
                            @endif
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="{{ $td }}">Letzter Sync (lokal)</td>
                    <td style="{{ $td }}">
                        {{ $lastSyncAt ?? 'noch nie' }}
                        @if ($lastSyncOk === true)
                            <span style="{{ $ok }}">ok</span>
                        @elseif ($lastSyncOk === false)
                            <span style="{{ $bad }}">mit Fehlern</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="{{ $td }}">Automatischer Abruf</td>
                    <td style="{{ $td }}">
                        @if ($scheduled)
                            alle 30 Minuten über den Scheduler (Cron für <code>schedule:run</code> nötig)
                        @else
                            aus (<code>SOCIAL_HUB_SCHEDULE=false</code>), Abruf nur beim Seitenaufruf
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="{{ $td }}">Webhook</td>
                    <td style="{{ $td }}">
                        <code>{{ $webhookUrl }}</code>
                        @if ($webhookConfigured)
                            <span style="{{ $ok }}">Secret gesetzt</span>
                        @else
                            <span style="{{ $neutral }}">kein Secret (nur für Posten nötig)</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="{{ $td }}">Addon-Version</td>
                    <td style="{{ $td }}">{{ $addonVersion }}</td>
                </tr>
                <tr>
                    <td style="{{ $td }}">Zugangsdaten</td>
                    <td style="{{ $td }}">{{ $usesEnvironment ? 'aus der .env der Seite' : 'aus dem Verbindungscode' }}</td>
                </tr>
            </table>
        @endif

        @if ($canConnect && ! $usesEnvironment)
            <form method="POST" action="{{ cp_route('social-hub.connect') }}" style="margin:16px 0 0;">
                @csrf
                <label for="social-hub-code" style="display:block;font-weight:600;font-size:14px;margin-bottom:6px;">
                    {{ $configured ? 'Neuen Verbindungscode einfügen' : 'Verbindungscode einfügen' }}
                </label>
                <textarea id="social-hub-code" name="code" rows="3" required autocomplete="off" spellcheck="false" placeholder="shc1.…" style="{{ $input }}"></textarea>
                <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                    <button type="submit" style="{{ $button }}">Verbinden</button>
                    <span style="font-size:13px;color:#6b7280;">Der Code wird geprüft, verschlüsselt gespeichert, danach werden die Beiträge geladen.</span>
                </div>
            </form>

            @if ($hasStoredCode)
                <form method="POST" action="{{ cp_route('social-hub.disconnect') }}" style="margin:12px 0 0;" onsubmit="return confirm('Verbindung zum Social Hub trennen? Die Seite zeigt dann nur noch die zuletzt geladenen Beiträge.');">
                    @csrf
                    <button type="submit" style="{{ $buttonSecondary }}">Verbindung trennen</button>
                </form>
            @endif
        @elseif (! $configured)
            <p style="margin:0;color:#6b7280;">Zum Verbinden ist das Recht „Mit dem Social Hub verbinden“ nötig.</p>
        @endif
    </div>

    {{-- Konten --}}
    <div style="{{ $box }}">
        <h2 style="font-size:16px;font-weight:600;margin:0 0 12px;">Konten</h2>

        @if (count($accounts) === 0)
            <p style="margin:0;color:#6b7280;">Keine Konten. Im Hub müssen der Seite Konten mit einem Handle zugewiesen sein.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="{{ $th }}">Handle (Tag)</th>
                            <th style="{{ $th }}">Plattform</th>
                            <th style="{{ $th }}">Konto</th>
                            <th style="{{ $th }}">Status</th>
                            <th style="{{ $th }}">Abruf beim Hub</th>
                            <th style="{{ $th }}">Letzter lokaler Sync</th>
                            <th style="{{ $th }}">Medien lokal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $account)
                            <tr>
                                <td style="{{ $td }}"><code>{{ $account['handle'] }}</code></td>
                                <td style="{{ $td }}">{{ $account['platform'] ?? '–' }}</td>
                                <td style="{{ $td }}">
                                    {{ $account['name'] ?? '' }}
                                    @if ($account['username'])
                                        <span style="color:#6b7280;">&#64;{{ $account['username'] }}</span>
                                    @endif
                                    @if ($account['can_publish'])
                                        <span style="{{ $neutral }}">posten möglich</span>
                                    @endif
                                </td>
                                <td style="{{ $td }}">
                                    <span style="{{ in_array($account['status'], ['active', 'ok', 'connected'], true) ? $ok : ($account['status'] ? $bad : $neutral) }}">{{ $account['status'] ?? 'unbekannt' }}</span>
                                </td>
                                <td style="{{ $td }}">{{ $account['hub_synced_at'] ?? '–' }}</td>
                                <td style="{{ $td }}">{{ $account['local_synced_at'] ?? '–' }}</td>
                                <td style="{{ $td }}">{{ $account['items'] }}</td>
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
            <p style="margin:12px 0 0;font-size:13px;color:#6b7280;">
                Im Template: <code>{{ $example }}</code>
            </p>
        @endif
    </div>

    {{-- Fehler --}}
    <div style="{{ $box }}">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px;">
            <h2 style="font-size:16px;font-weight:600;margin:0;">Letzte Fehler</h2>

            @if ($canManage && count($hubErrors) > 0)
                <form method="POST" action="{{ cp_route('social-hub.sync') }}" style="margin:0;">
                    @csrf
                    <input type="hidden" name="clear_errors" value="1">
                    <button type="submit" style="{{ $buttonSecondary }}">Liste leeren</button>
                </form>
            @endif
        </div>

        @if (count($hubErrors) === 0)
            <p style="margin:0;color:#6b7280;">Keine Fehler.</p>
        @else
            <table style="width:100%;border-collapse:collapse;">
                <tbody>
                    @foreach ($hubErrors as $error)
                        <tr>
                            <td style="{{ $td }}white-space:nowrap;width:140px;">{{ $error['time'] }}</td>
                            <td style="{{ $td }}white-space:nowrap;width:180px;">{{ $error['context'] }}</td>
                            <td style="{{ $td }}">{{ $error['message'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
@endsection
