<?php

namespace WursterMedien\SocialHub\Actions;

use Illuminate\Support\Carbon;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use WursterMedien\SocialHub\Hub\HubClient;
use WursterMedien\SocialHub\Hub\HubException;
use WursterMedien\SocialHub\Hub\HubRequestException;
use WursterMedien\SocialHub\Posts\InvalidPostException;
use WursterMedien\SocialHub\Posts\PostPayloadBuilder;
use WursterMedien\SocialHub\Posts\PostStatusWriter;

/**
 * Aktion "An Social Hub senden" für Einträge mit dem Fieldset social_hub.
 *
 * Wird nur ausgeführt, wenn jemand sie ausdrücklich im Control Panel startet.
 * Beim Speichern eines Eintrags wird nie etwas gesendet.
 */
class SendToSocialHub extends Action
{
    protected static $handle = 'send_to_social_hub';

    protected $icon = 'megaphone';

    protected $fields = [
        'submit' => [
            'type' => 'toggle',
            'display' => 'Zur Freigabe bzw. Veröffentlichung einreichen',
            'instructions' => 'Aus: Der Post wird im Hub nur als Entwurf gespeichert.',
            'default' => true,
        ],
    ];

    public static function title()
    {
        return 'An Social Hub senden';
    }

    public function visibleTo($item)
    {
        return $item instanceof Entry
            && $item->blueprint()?->hasField('social_hub_targets');
    }

    public function authorize($user, $item)
    {
        return $user->can('edit', $item) && $user->can('manage social hub');
    }

    public function buttonText()
    {
        return 'An Social Hub senden|:count Einträge an Social Hub senden';
    }

    public function confirmationText()
    {
        return 'Den Eintrag jetzt an den Social Hub senden? Nicht gespeicherte Änderungen werden nicht mitgeschickt.|:count Einträge jetzt an den Social Hub senden?';
    }

    public function run($items, $values)
    {
        $builder = app(PostPayloadBuilder::class);
        $client = app(HubClient::class);
        $writer = app(PostStatusWriter::class);
        $submit = (bool) ($values['submit'] ?? true);

        $sent = 0;

        foreach ($items as $entry) {
            $title = (string) ($entry->get('title') ?: $entry->id());

            try {
                $post = $client->createPost($builder->build($entry, $submit));
            } catch (InvalidPostException $exception) {
                throw new \Exception("„{$title}“: {$exception->getMessage()}");
            } catch (HubRequestException $exception) {
                throw new \Exception("„{$title}“: {$this->describe($exception)}");
            } catch (HubException $exception) {
                throw new \Exception("„{$title}“: {$exception->getMessage()}");
            }

            $entry->set('social_hub_sent_at', Carbon::now()->format('Y-m-d H:i'));
            $writer->write($entry, $post);
            $sent++;
        }

        return $sent === 1
            ? 'An den Social Hub gesendet.'
            : "{$sent} Einträge an den Social Hub gesendet.";
    }

    protected function describe(HubRequestException $exception): string
    {
        if ($exception->isConflict()) {
            return 'Der Post ist im Hub bereits freigegeben oder veröffentlicht und kann nicht mehr geändert werden.';
        }

        if ($exception->isValidationError()) {
            $messages = $exception->errorMessages();

            return 'Der Hub hat den Post abgelehnt: '.($messages !== [] ? implode(' ', $messages) : ($exception->hubMessage ?? 'ungültige Daten'));
        }

        if ($exception->isUnauthorized()) {
            return 'Der Hub hat den Zugriff verweigert. Bitte API-Key und Kontenfreigabe prüfen.';
        }

        return $exception->getMessage();
    }
}
