<?php

namespace WursterMedien\SocialHub\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use WursterMedien\SocialHub\Sync\Synchronizer;

class SyncCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'social-hub:sync
        {--account=* : Nur dieses Konto (Handle) abgleichen, mehrfach möglich}';

    protected $description = 'Konten und Feeds vom Social Hub abrufen und Medien lokal spiegeln';

    public function handle(Synchronizer $synchronizer): int
    {
        $result = $synchronizer->run(array_values(array_filter((array) $this->option('account'))));

        if ($result->pingOk) {
            $this->components->info('Verbunden mit dem Social Hub'.($result->siteName ? ' als "'.$result->siteName.'"' : '').'.');
        }

        if ($result->feeds !== []) {
            $rows = [];

            foreach ($result->feeds as $handle => $feed) {
                $rows[] = [$handle, $feed['ok'] ? 'ok' : 'Fehler', $feed['items'], $feed['message'] ?? ''];
            }

            $this->table(['Konto', 'Status', 'Medien', 'Hinweis'], $rows);
        }

        $this->line(sprintf(
            'Medien: %d geladen, %d vorhanden, %d fehlgeschlagen, %d gelöscht.',
            $result->media['downloaded'],
            $result->media['existing'],
            $result->media['failed'],
            $result->pruned,
        ));

        foreach ($result->errors as $error) {
            $this->components->error($error);
        }

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}
