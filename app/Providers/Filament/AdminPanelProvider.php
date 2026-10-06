<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AdminSessionTimeout;
use App\Http\Middleware\RestrictAdminIps;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
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
            ->favicon(asset('favicon.svg'))
            ->colors([
                'primary' => Color::hex('#12403A'),
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
            ])
            ->authMiddleware([
                Authenticate::class,
                AdminSessionTimeout::class,
            ]);
    }
}
