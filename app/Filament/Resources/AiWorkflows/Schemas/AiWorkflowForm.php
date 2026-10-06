<?php

namespace App\Filament\Resources\AiWorkflows\Schemas;

use App\Filament\Support\Catalogue\WorkflowConfigForm;
use App\Models\AiWorkflow;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AiWorkflowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Workflow')
                ->description(fn (?AiWorkflow $record): ?string => $record?->exists
                    ? "Version {$record->version}. Changing the configuration creates a new version; jobs already running keep the configuration they started with."
                    : null)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, string $operation): void {
                            if ($operation === 'create' && ($get('slug') ?? '') === Str::slug((string) $old)) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(120)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique(ignoreRecord: true),
                    Textarea::make('description')
                        ->rows(2)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->live()
                        ->rules([self::defaultMustBeActiveRule()])
                        ->helperText('Inactive workflows are never used for new orders.'),
                    Toggle::make('is_default')
                        ->label('Default workflow')
                        ->disabled(fn (?AiWorkflow $record): bool => (bool) $record?->getOriginal('is_default'))
                        ->helperText(fn (?AiWorkflow $record): string => $record?->getOriginal('is_default')
                            ? 'This is the default. To change it, make another workflow the default.'
                            : 'Used by services without a specific workflow. Replaces the current default.'),
                    Select::make('fallback_workflow_id')
                        ->label('Fallback workflow')
                        ->options(fn (?AiWorkflow $record): array => AiWorkflow::query()
                            ->when($record?->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->placeholder('None')
                        ->native(false)
                        ->helperText('Used when a limit is reached and “Continue with the fallback workflow” is selected (e.g. a cheaper configuration).')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Tabs::make('Configuration')
                ->persistTabInQueryString('config-tab')
                ->tabs(WorkflowConfigForm::tabs())
                ->columnSpanFull(),
        ]);
    }

    private static function defaultMustBeActiveRule(): Closure
    {
        return fn (Get $get, ?AiWorkflow $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            $isDefault = (bool) $get('is_default') || (bool) $record?->getOriginal('is_default');
            if (! $value && $isDefault) {
                $fail('The default workflow must stay active. Make another workflow the default first.');
            }
        };
    }
}
