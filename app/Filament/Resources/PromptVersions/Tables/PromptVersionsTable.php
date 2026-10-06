<?php

namespace App\Filament\Resources\PromptVersions\Tables;

use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Filament\Resources\PromptVersions\Schemas\PromptVersionInfolist;
use App\Filament\Support\Catalogue\PromptActivation;
use App\Filament\Support\Catalogue\PromptDiff;
use App\Models\PromptVersion;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PromptVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['activatedBy:id,name', 'createdBy:id,name']))
            ->defaultGroup(Group::make('prompt_key')->label('Prompt')->collapsible())
            ->defaultSort('version', 'desc')
            ->recordUrl(fn (PromptVersion $record): string => PromptVersionResource::getUrl(
                $record->status === PromptVersion::STATUS_DRAFT && PromptVersionResource::canEdit($record) ? 'edit' : 'view',
                ['record' => $record],
            ))
            ->columns([
                TextColumn::make('version')->prefix('v')->sortable()->weight('medium'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => PromptVersionInfolist::statusColor($state)),
                TextColumn::make('label')->placeholder('—')->limit(60)->searchable(),
                TextColumn::make('prompt_key')->label('Key')->fontFamily(FontFamily::Mono)->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('model')->label('Model')->placeholder('workflow')->toggleable(),
                TextColumn::make('activatedBy.name')->label('Activated by')->placeholder('—'),
                TextColumn::make('activated_at')->label('Activated')->dateTime('j M Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('createdBy.name')->label('Created by')->placeholder('System')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('prompt_key')
                    ->label('Prompt key')
                    ->options(fn (): array => PromptVersion::query()->distinct()->orderBy('prompt_key')->pluck('prompt_key', 'prompt_key')->all()),
                SelectFilter::make('status')->options([
                    PromptVersion::STATUS_ACTIVE => 'Active',
                    PromptVersion::STATUS_DRAFT => 'Draft',
                    PromptVersion::STATUS_ARCHIVED => 'Archived',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    PromptActivation::action(),
                    self::compareAction(),
                    self::newDraftAction(),
                    DeleteAction::make()->modalDescription('Deletes this draft. Active and archived versions are kept for the record.'),
                ]),
            ])
            ->emptyStateHeading('No prompt versions yet')
            ->emptyStateDescription('Create the first version of a prompt key; it stays a draft until you activate it.');
    }

    public static function compareAction(): Action
    {
        return Action::make('compare')
            ->label('Compare with active')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (PromptVersion $record): bool => $record->status !== PromptVersion::STATUS_ACTIVE)
            ->modalHeading(fn (PromptVersion $record): string => "{$record->prompt_key}: v{$record->version} compared with the active version")
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (PromptVersion $record) => PromptDiff::compare($record));
    }

    public static function newDraftAction(): Action
    {
        return Action::make('newDraft')
            ->label('New draft from this version')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('gray')
            ->authorize('create')
            ->url(fn (PromptVersion $record): string => PromptVersionResource::getUrl('create', ['from' => $record->getKey()]));
    }
}
