<?php

namespace App\Filament\Resources\AdminUsers\Schemas;

use App\Filament\Support\Catalogue\AdminAccess;
use App\Filament\Support\Catalogue\AdminAccounts;
use App\Filament\Support\Catalogue\PermissionLabels;
use App\Models\AdminUser;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Password;

class AdminUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->disabled(fn (?AdminUser $record): bool => $record !== null && AdminAccess::user()?->is($record) === true)
                        ->rules([self::safetyRule('is_active')])
                        ->helperText(fn (?AdminUser $record): string => $record !== null && AdminAccess::user()?->is($record) === true
                            ? 'You cannot deactivate your own account.'
                            : 'Inactive administrators cannot sign in.'),
                    Text::make(fn (?AdminUser $record): HtmlString => self::securitySummary($record))
                        ->visible(fn (?AdminUser $record): bool => (bool) $record?->exists),
                ])
                ->columns(2),

            Section::make(fn (string $operation): string => $operation === 'create' ? 'Password' : 'Change password')
                ->description(fn (string $operation): string => $operation === 'create'
                    ? 'The administrator must also set up an authenticator app (two-factor authentication) the first time they sign in.'
                    : 'Leave blank to keep the current password.')
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->rule(Password::defaults())
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->live(onBlur: true)
                        ->helperText('At least 12 characters with upper- and lower-case letters, numbers and symbols.'),
                    TextInput::make('password_confirmation')
                        ->label('Confirm password')
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->required(fn (Get $get): bool => filled($get('password')))
                        ->same('password')
                        ->dehydrated(false),
                ])
                ->columns(2),

            Section::make('Roles')
                ->description('What this administrator can see and do. Role permissions are listed under System → Roles.')
                ->schema([
                    CheckboxList::make('roles')
                        ->hiddenLabel()
                        ->options(PermissionLabels::roleOptions())
                        ->descriptions(fn (): array => array_filter(array_map(
                            fn (string $role): ?string => PermissionLabels::roleDescription($role),
                            array_combine(array_keys(PermissionLabels::roleOptions()), array_keys(PermissionLabels::roleOptions())),
                        )))
                        ->required()
                        ->minItems(1)
                        ->rules([self::safetyRule('roles')])
                        ->validationMessages(['required' => 'Choose at least one role; administrators without a role cannot sign in.']),
                ]),
        ]);
    }

    /** Blocks deactivating yourself and removing the last active Super Admin. */
    private static function safetyRule(string $field): Closure
    {
        return fn (Get $get, ?AdminUser $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $field): void {
            $roles = array_values(array_filter((array) ($field === 'roles' ? $value : $get('roles'))));

            // Your own "active" toggle is locked on (and forced on when saving), whatever the request says.
            $isSelf = $record !== null && AdminAccess::user()?->is($record) === true;
            $active = $isSelf || (bool) ($field === 'is_active' ? $value : ($get('is_active') ?? $record?->is_active ?? true));

            if ($message = AdminAccounts::problem($record, $roles, $active)) {
                $fail($message);
            }
        };
    }

    private static function securitySummary(?AdminUser $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('');
        }

        $mfa = $record->hasMfaEnabled()
            ? '<strong style="color: var(--success-600);">Two-factor authentication enabled</strong>'
            : '<strong style="color: var(--warning-600);">Two-factor authentication not set up yet</strong> — required at next sign-in.';
        $login = $record->last_login_at
            ? 'Last sign-in '.e($record->last_login_at->diffForHumans()).($record->last_login_ip ? ' from '.e($record->last_login_ip) : '').'.'
            : 'Has never signed in.';

        return new HtmlString($mfa.'<br><span style="color: var(--gray-500);">'.$login.'</span>');
    }
}
