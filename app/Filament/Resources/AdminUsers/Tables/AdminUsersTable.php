<?php

namespace App\Filament\Resources\AdminUsers\Tables;

use App\Enums\AdminRole;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Filament\Support\Catalogue\AdminAccounts;
use App\Filament\Support\Catalogue\PermissionLabels;
use App\Models\AdminUser;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AdminUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (AdminUser $record): string => $record->email)
                    ->suffix(fn (AdminUser $record): string => AdminAccess::user()?->is($record) ? ' (you)' : ''),
                TextColumn::make('role_names')
                    ->label('Roles')
                    ->state(fn (AdminUser $record): array => array_map(PermissionLabels::role(...), AdminAccounts::roleNames($record)))
                    ->badge()
                    ->color(fn (string $state): string => $state === AdminRole::SuperAdmin->getLabel() ? 'danger' : 'gray'),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                IconColumn::make('mfa')
                    ->label('2FA')
                    ->state(fn (AdminUser $record): bool => $record->hasMfaEnabled())
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedShieldCheck)
                    ->falseIcon(Heroicon::OutlinedShieldExclamation)
                    ->falseColor('warning')
                    ->tooltip(fn (AdminUser $record): string => $record->hasMfaEnabled() ? 'Authenticator app set up' : 'Not set up — required at next sign-in'),
                TextColumn::make('last_login_at')->label('Last sign-in')->since()->placeholder('Never')->sortable(),
                TextColumn::make('created_at')->label('Added')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(PermissionLabels::roleOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('roles', fn (Builder $q) => $q->where('name', $data['value']))
                        : $query),
                TernaryFilter::make('is_active')->label('Active'),
                TernaryFilter::make('mfa')
                    ->label('Two-factor authentication')
                    ->trueLabel('Set up')
                    ->falseLabel('Not set up')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('app_authentication_secret'),
                        false: fn (Builder $query) => $query->whereNull('app_authentication_secret'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                self::resetMfaAction(),
            ]);
    }

    public static function resetMfaAction(): Action
    {
        return Action::make('resetMfa')
            ->label('Reset 2FA')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->authorize('resetMfa')
            ->requiresConfirmation()
            ->modalHeading(fn (AdminUser $record): string => "Reset two-factor authentication for {$record->name}?")
            ->modalDescription('Their authenticator app and recovery codes stop working. They must set up two-factor authentication again at their next sign-in. Only do this after confirming their identity.')
            ->modalSubmitActionLabel('Reset 2FA')
            ->action(function (AdminUser $record): void {
                $actor = AdminAccess::user();
                abort_unless($actor && Gate::forUser($actor)->allows('resetMfa', $record), 403);

                AdminAccounts::resetMfa($record, $actor);

                Notification::make()->success()->title('Two-factor authentication reset')->send();
            });
    }
}
