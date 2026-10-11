<?php

namespace App\Filament\Resources\PartnerApplications\Tables;

use App\Filament\Resources\PartnerApplications\PartnerApplicationActions;
use App\Filament\Resources\PartnerApplications\PartnerApplicationResource;
use App\Filament\Resources\PartnerApplications\Schemas\PartnerApplicationInfolist;
use App\Filament\Support\Operations\Format;
use App\Models\PartnerApplication;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Applications, newest first; applications whose email is not confirmed are hidden unless asked for. */
class PartnerApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Applied')
                    ->dateTime(Format::DATETIME)
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label('Applicant')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (PartnerApplication $record): string => $record->email)
                    ->searchable(['full_name', 'email']),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->description(fn (PartnerApplication $record): string => $record->city),
                TextColumn::make('channels')
                    ->label('Channels')
                    ->state(fn (PartnerApplication $record): array => $record->channelLabels())
                    ->badge()
                    ->limitList(2),
                TextColumn::make('audience_size')
                    ->label('Audience')
                    ->formatStateUsing(fn (string $state): string => PartnerApplication::AUDIENCE_SIZES[$state] ?? $state)
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PartnerApplication $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => PartnerApplicationInfolist::color($state))
                    ->icon(fn (PartnerApplication $record) => PartnerApplicationResource::duplicateWarnings($record) ? Heroicon::OutlinedExclamationTriangle : false)
                    ->tooltip(fn (PartnerApplication $record): ?string => implode(' ', PartnerApplicationResource::duplicateWarnings($record)) ?: null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(PartnerApplication::statusLabels()),
                Filter::make('hide_unconfirmed')
                    ->label('Hide applications whose email is not confirmed')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('partner_applications.status', '!=', PartnerApplication::STATUS_UNCONFIRMED)),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    PartnerApplicationActions::approve(),
                    PartnerApplicationActions::reject(),
                ])->tooltip('Decide'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading('No applications')
            ->emptyStateDescription('Applications from the “Become a partner” page appear here once the applicant confirms their email.')
            ->striped()
            ->defaultPaginationPageOption(25);
    }
}
