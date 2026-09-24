<?php

namespace WursterMedien\SocialHub;

use Illuminate\Console\Scheduling\Schedule;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use WursterMedien\SocialHub\Accounts\AccountRepository;
use WursterMedien\SocialHub\Actions\SendToSocialHub;
use WursterMedien\SocialHub\Commands\SyncCommand;
use WursterMedien\SocialHub\Dictionaries\SocialHubAccounts;
use WursterMedien\SocialHub\Feeds\FeedRepository;
use WursterMedien\SocialHub\Feeds\MediaMirror;
use WursterMedien\SocialHub\Hub\HubClient;
use WursterMedien\SocialHub\Support\HubConnection;
use WursterMedien\SocialHub\Support\StateStore;
use WursterMedien\SocialHub\Support\SyncStatus;
use WursterMedien\SocialHub\Tags\Social;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        Social::class,
    ];

    protected $actions = [
        SendToSocialHub::class,
    ];

    protected $dictionaries = [
        SocialHubAccounts::class,
    ];

    protected $commands = [
        SyncCommand::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'actions' => __DIR__.'/../routes/actions.php',
    ];

    protected $viewNamespace = 'social-hub';

    protected $fieldsetNamespace = 'social-hub';

    public function register(): void
    {
        parent::register();

        $this->app->singleton(HubConnection::class);
        $this->app->singleton(HubClient::class);
        $this->app->singleton(StateStore::class);
        $this->app->singleton(SyncStatus::class);
        $this->app->singleton(MediaMirror::class);
        $this->app->singleton(AccountRepository::class);
        $this->app->singleton(FeedRepository::class);
    }

    public function bootAddon(): void
    {
        $this->registerMediaDisk();
        $this->registerPermissions();
        $this->registerNavigation();
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('social-hub:sync')
            ->everyThirtyMinutes()
            ->withoutOverlapping(30)
            ->when(fn () => (bool) config('social-hub.schedule', true) && app(HubConnection::class)->isConfigured());
    }

    /**
     * Disk "social-hub" → public/{media_path}, falls die Seite keine eigene definiert.
     */
    protected function registerMediaDisk(): void
    {
        if (config('filesystems.disks.'.MediaMirror::DISK) === null) {
            config(['filesystems.disks.'.MediaMirror::DISK => MediaMirror::diskConfig()]);
        }
    }

    protected function registerPermissions(): void
    {
        Permission::extend(function () {
            Permission::group('social_hub', 'Social Hub', function () {
                Permission::register('view social hub', function ($permission) {
                    $permission
                        ->label('Social Hub ansehen')
                        ->description('Verbindungsstatus, Konten und Fehler im Control Panel sehen')
                        ->children([
                            Permission::make('manage social hub')
                                ->label('Social Hub verwalten')
                                ->description('Synchronisieren und Einträge an den Social Hub senden')
                                ->children([
                                    Permission::make('connect social hub')
                                        ->label('Mit dem Social Hub verbinden')
                                        ->description('Verbindungscode einfügen oder die Verbindung trennen'),
                                ]),
                        ]);
                });
            });
        });
    }

    protected function registerNavigation(): void
    {
        Nav::extend(function ($nav) {
            $nav->tools('Social Hub')
                ->route('social-hub.index')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>')
                ->can('view social hub');
        });
    }
}
