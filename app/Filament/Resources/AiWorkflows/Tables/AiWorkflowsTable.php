<?php

namespace App\Filament\Resources\AiWorkflows\Tables;

use App\Filament\Resources\AiWorkflows\AiWorkflowResource;
use App\Models\AiJob;
use App\Models\AiWorkflow;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AiWorkflowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('fallback')
                ->withCount(['services', 'services as all_services_count' => fn (Builder $q) => $q->withTrashed()])
                ->addSelect(['jobs_count' => AiJob::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('ai_jobs.ai_workflow_id', 'ai_workflows.id')]))
            ->defaultSort('is_default', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (AiWorkflow $record): ?string => Str::limit((string) $record->description, 90) ?: null),
                TextColumn::make('default')
                    ->label('')
                    ->state(fn (AiWorkflow $record): ?string => $record->is_default ? 'Default' : null)
                    ->badge()
                    ->color('success'),
                TextColumn::make('version')->label('Version')->prefix('v')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('fallback.name')->label('Fallback')->placeholder('—'),
                TextColumn::make('services_count')->label('Services')->numeric(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                self::duplicateAction(),
                DeleteAction::make()
                    ->modalDescription('Only workflows that never produced a document can be deleted.')
                    ->after(fn (AiWorkflow $record) => Audit::log('ai_workflow.deleted', $record, ['name' => $record->name, 'version' => $record->version])),
            ]);
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Creates an inactive copy of this workflow to experiment with. It is not used until you activate it and assign it to a service or make it the default.')
            ->authorize('create')
            ->action(function (AiWorkflow $record, Action $action): void {
                $copy = $record->replicate(['slug', 'services_count', 'all_services_count', 'jobs_count']);
                $copy->name = Str::limit($record->name.' (copy)', 120, '');
                $copy->slug = self::uniqueSlug($record->slug.'-copy');
                $copy->is_default = false;
                $copy->is_active = false;
                $copy->version = 1;
                $copy->save();

                Audit::log('ai_workflow.duplicated', $copy, null, ['name' => $copy->name], ['source_workflow_id' => $record->id]);
                Notification::make()->success()->title('Workflow duplicated')->send();

                $action->redirect(AiWorkflowResource::getUrl('edit', ['record' => $copy]));
            });
    }

    private static function uniqueSlug(string $base): string
    {
        $base = Str::limit(Str::slug($base), 110, '');
        $slug = $base;
        $i = 2;
        while (AiWorkflow::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
