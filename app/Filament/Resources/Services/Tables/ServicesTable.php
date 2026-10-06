<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\DocumentKind;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Support\Catalogue\ServiceAccess;
use App\Filament\Support\Catalogue\ServiceArchiver;
use App\Filament\Support\Catalogue\ServiceDuplicator;
use App\Filament\Support\Catalogue\AdminAccess;
use App\Models\Service;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['fields', 'orders']))
            ->defaultSort('display_order')
            ->reorderable('display_order', fn (): bool => ServiceAccess::canEditContent())
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Service $record): string => '/services/'.$record->slug)
                    ->icon(fn (Service $record) => $record->trashed() ? Heroicon::OutlinedArchiveBox : null),
                TextColumn::make('document_kind')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => DocumentKind::tryFrom((string) $state)?->getLabel() ?? (string) $state)
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Price')
                    ->formatStateUsing(fn (Service $record): string => Money::format($record->price, $record->currency))
                    ->description(fn (Service $record): ?string => $record->compare_at_price > $record->price
                        ? 'was '.Money::format($record->compare_at_price, $record->currency)
                        : null)
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedStar)
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->toggleable(),
                TextColumn::make('badge')
                    ->badge()
                    ->color('success')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('fields_count')
                    ->label('Questions')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->label('Archived')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                SelectFilter::make('document_kind')->label('Document type')->options(DocumentKind::class),
                TrashedFilter::make()->label('Archived services'),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    self::duplicateAction(),
                    DeleteAction::make()
                        ->label('Archive')
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->modalHeading(fn (Service $record): string => "Archive {$record->name}?")
                        ->modalDescription('The service disappears from the site and can no longer be ordered. Existing orders are not affected, and you can restore it at any time.')
                        ->modalSubmitActionLabel('Archive')
                        ->using(fn (Service $record): bool => ServiceArchiver::archive($record))
                        ->successNotificationTitle('Service archived'),
                    RestoreAction::make()
                        ->using(fn (Service $record): bool => ServiceArchiver::restore($record))
                        ->successNotificationTitle('Service restored (inactive — activate it when ready)'),
                    ForceDeleteAction::make()
                        ->label('Delete permanently')
                        ->modalDescription('This permanently deletes the service, its order form and FAQs. This cannot be undone. Only services without any orders can be deleted.')
                        ->using(fn (Service $record): bool => ServiceArchiver::forceDelete($record)),
                ]),
            ])
            ->emptyStateHeading('No services yet')
            ->emptyStateDescription('Create your first service: name, price, order form and AI settings — no developer needed.');
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->requiresConfirmation()
            ->modalHeading(fn (Service $record): string => "Duplicate {$record->name}?")
            ->modalDescription('Creates an inactive copy with the same order form, settings and FAQs, so you can adapt it into a new service.')
            ->modalSubmitActionLabel('Duplicate')
            ->visible(fn (): bool => ServiceAccess::canCreate())
            ->action(function (Service $record, Action $action) {
                abort_unless(ServiceAccess::canCreate(), 403);

                $copy = ServiceDuplicator::duplicate($record, AdminAccess::user());

                Notification::make()
                    ->success()
                    ->title('Service duplicated')
                    ->body("“{$copy->name}” was created as an inactive copy.")
                    ->send();

                $action->redirect(ServiceResource::getUrl('edit', ['record' => $copy]));
            });
    }
}
