<?php

namespace App\Filament\Resources\PartnerApplications\Schemas;

use App\Filament\Resources\PartnerApplications\PartnerApplicationResource;
use App\Filament\Resources\Referrers\ReferrerResource;
use App\Filament\Support\Operations\Format;
use App\Models\PartnerApplication;
use App\Support\Countries;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PartnerApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Callout::make('Possible duplicate')
                    ->description(fn (PartnerApplication $record): string => implode(' ', PartnerApplicationResource::duplicateWarnings($record)))
                    ->warning()
                    ->visible(fn (PartnerApplication $record): bool => PartnerApplicationResource::duplicateWarnings($record) !== [])
                    ->columnSpanFull(),
                Section::make('Applicant')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('full_name')->label('Name'),
                        TextEntry::make('email')->label('Email')->copyable(),
                        TextEntry::make('phone')->label('Phone')->copyable(),
                        TextEntry::make('location')
                            ->label('Location')
                            ->state(fn (PartnerApplication $record): string => $record->city.', '.(Countries::name($record->country_code) ?? $record->country_code)),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (PartnerApplication $record): string => $record->statusLabel())
                            ->color(fn (string $state): string => self::color($state)),
                        TextEntry::make('created_at')->label('Applied')->dateTime(Format::DATETIME)
                            ->helperText(fn (PartnerApplication $record): ?string => $record->confirmed_at ? 'Email confirmed '.Format::dateTime($record->confirmed_at) : 'Email not confirmed yet'),
                    ]),
                Section::make('How they would promote Statementra')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('channels')
                            ->label('Channels')
                            ->state(fn (PartnerApplication $record): array => $record->channelLabels())
                            ->badge(),
                        TextEntry::make('audience')
                            ->label('Audience size')
                            ->state(fn (PartnerApplication $record): string => PartnerApplication::AUDIENCE_SIZES[$record->audience_size] ?? $record->audience_size),
                        TextEntry::make('promotion_details')->label('More detail')->placeholder(Format::PLACEHOLDER)->columnSpanFull(),
                        TextEntry::make('links')
                            ->label('Profile links')
                            ->state(fn (PartnerApplication $record): ?HtmlString => $record->profile_links
                                ? new HtmlString(implode('<br>', array_map(fn (string $link) => e($link), (array) $record->profile_links)))
                                : null)
                            ->helperText('Shown as text: check the address before opening it.')
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('motivation')->label('Why they want to partner')->columnSpanFull(),
                        TextEntry::make('preferred_code')->label('Preferred code')->placeholder('None'),
                    ]),
                Section::make('Review')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('admin_notes')->label('Internal notes')->placeholder('No notes')->columnSpanFull(),
                        TextEntry::make('decided_at')->label('Decided')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('decidedBy.name')->label('By')->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('rejection_reason')->label('Rejection reason')->placeholder(Format::PLACEHOLDER)
                            ->visible(fn (PartnerApplication $record): bool => $record->status === PartnerApplication::STATUS_REJECTED),
                        TextEntry::make('referrer_link')
                            ->label('Referrer')
                            ->state(fn (PartnerApplication $record): ?string => $record->referrer ? $record->referrer->name.' ('.$record->referrer->code.')' : null)
                            ->url(fn (PartnerApplication $record): ?string => $record->referrer && ReferrerResource::canView($record->referrer)
                                ? ReferrerResource::getUrl(ReferrerResource::canEdit($record->referrer) ? 'edit' : 'view', ['record' => $record->referrer])
                                : null)
                            ->visible(fn (PartnerApplication $record): bool => $record->referrer_id !== null),
                    ]),
            ]);
    }

    public static function color(string $status): string
    {
        return match ($status) {
            PartnerApplication::STATUS_SUBMITTED => 'warning',
            PartnerApplication::STATUS_APPROVED => 'success',
            PartnerApplication::STATUS_REJECTED => 'danger',
            default => 'gray',
        };
    }
}
