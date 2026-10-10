<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\InitialsAvatarProvider;
use App\Http\Middleware\AdminSessionTimeout;
use App\Http\Middleware\RestrictAdminIps;
use App\Http\Middleware\RunHeartbeat;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The Statementra admin panel: a separate guard and user table, mandatory
 * multi-factor authentication, role-based access (spatie/laravel-permission)
 * and an optional IP allowlist.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path(trim((string) config('statementra.security.admin_path', 'admin'), '/'))
            ->authGuard('admin')
            ->authPasswordBroker('admin_users')
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()->brandName(config('app.name').' Admin')],
                isRequired: true,
            )
            ->brandName('Statementra Admin')
            ->brandLogo(asset('images/brand/statementra-logo.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg').'?v=2')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                // The site's brand greens, shade for shade (Color::hex() would derive lighter teals).
                'primary' => array_map(fn (string $hex) => Color::convertToOklch($hex), [
                    50 => '#eef5f2', 100 => '#dcebe5', 200 => '#b9d6cb', 300 => '#8ebaa9', 400 => '#5b9584',
                    500 => '#2f7364', 600 => '#1c5a51', 700 => '#12403a', 800 => '#0e332e', 900 => '#0a2622', 950 => '#06171a',
                ]),
                'gray' => Color::Stone,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->font('Inter', url: asset('fonts/inter/inter.css'), provider: LocalFontProvider::class)
            ->darkMode(false)
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->unsavedChangesAlerts()
            ->navigationGroups([
                NavigationGroup::make('Orders'),
                NavigationGroup::make('Catalogue'),
                NavigationGroup::make('Pricing'),
                NavigationGroup::make('Content'),
                NavigationGroup::make('AI'),
                NavigationGroup::make('Customers'),
                NavigationGroup::make('Reports'),
                NavigationGroup::make('System')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                RestrictAdminIps::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                RunHeartbeat::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                AdminSessionTimeout::class,
            ]);
    }
}
