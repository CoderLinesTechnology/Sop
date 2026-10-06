<?php

namespace App\Filament\Resources\PromptVersions\Schemas;

use App\Filament\Support\Catalogue\AiOptions;
use App\Models\PromptVersion;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PromptVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Version')
                ->schema([
                    TextInput::make('prompt_key')
                        ->label('Prompt key')
                        ->required()
                        ->maxLength(60)
                        ->regex(AiOptions::PROMPT_KEY_PATTERN)
                        ->datalist(fn (): array => AiOptions::promptKeys())
                        ->disabledOn('edit')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                            if ($operation !== 'create' || blank($state) || filled($get('system_prompt'))) {
                                return;
                            }
                            $active = PromptVersion::activeFor($state);
                            if ($active) {
                                $set('system_prompt', $active->system_prompt);
                                $set('user_template', $active->user_template);
                                $set('model', $active->model);
                                $set('reasoning_effort', $active->reasoning_effort);
                            } elseif ($builtIn = AiOptions::builtInPrompt($state)) {
                                $set('system_prompt', $builtIn['system_prompt']);
                                $set('user_template', $builtIn['user_template']);
                            }
                        })
                        ->validationMessages(['regex' => 'Use lowercase letters, numbers, dots, dashes and underscores (e.g. writing).'])
                        ->helperText('Which prompt this is a version of. Workflows pick prompts by key for each stage.'),
                    Text::make(function (Get $get, ?PromptVersion $record): string {
                        if ($record?->exists) {
                            return "Version {$record->version} · ".ucfirst((string) $record->status).'. Drafts can be edited; once activated, a version can no longer change.';
                        }
                        $key = (string) $get('prompt_key');

                        return $key !== '' && preg_match(AiOptions::PROMPT_KEY_PATTERN, $key)
                            ? 'Will be saved as version '.PromptVersion::nextVersionNumber($key).' of “'.$key.'”, as a draft. It changes nothing until it is activated.'
                            : 'New versions are saved as drafts and change nothing until they are activated.';
                    })->color('gray'),
                    TextInput::make('label')
                        ->maxLength(255)
                        ->placeholder('e.g. Stronger, more specific openings')
                        ->helperText('Short name for this version.'),
                    Textarea::make('description')
                        ->label('What changed and why')
                        ->rows(2)
                        ->maxLength(5000),
                ])
                ->columns(1),
            Section::make('Prompt')
                ->schema([
                    Textarea::make('system_prompt')
                        ->label('System prompt')
                        ->required()
                        ->rows(18)
                        ->maxLength(200000)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem;'])
                        ->helperText('Trusted instructions. Customer data is always passed separately as delimited data, never inside these instructions.'),
                    Textarea::make('user_template')
                        ->label('User message template')
                        ->rows(10)
                        ->maxLength(200000)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8125rem;'])
                        ->helperText('Optional. Keep the placeholders the pipeline fills for this stage (compare with the active version).'),
                ]),
            Section::make('Model fallback')
                ->description('Only used when the workflow does not set a model or reasoning effort for the stage. Workflows normally do, so these are usually left blank.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('model')
                            ->maxLength(80)
                            ->datalist(fn (): array => AiOptions::models())
                            ->placeholder('Not set'),
                        Select::make('reasoning_effort')
                            ->label('Reasoning effort')
                            ->options(AiOptions::REASONING_EFFORTS)
                            ->placeholder('Not set')
                            ->native(false),
                    ]),
                ])
                ->collapsible(),
        ]);
    }
}
