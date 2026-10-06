<?php

namespace App\Filament\Resources\EmailTemplates\Schemas;

use App\Enums\EmailTemplateKey;
use App\Filament\Support\Catalogue\SampleEmailVariables;
use App\Models\EmailTemplate;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class EmailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->schema([
                    Group::make([
                        Section::make('Email')
                            ->schema([
                                TextInput::make('subject')
                                    ->required()
                                    ->maxLength(200)
                                    ->helperText('Variables work in the subject too, e.g. “Your secure link for order {{order_id}}”.'),
                                MarkdownEditor::make('body')
                                    ->required()
                                    ->maxLength(20000)
                                    ->minHeight('22rem')
                                    ->toolbarButtons([
                                        ['bold', 'italic', 'link'],
                                        ['heading', 'bulletList', 'orderedList'],
                                        ['undo', 'redo'],
                                    ])
                                    ->helperText('Markdown. Customer-supplied values are inserted safely as plain text.'),
                            ]),
                    ])->columnSpan(2),
                    Group::make([
                        Section::make('Status')
                            ->schema([
                                Toggle::make('is_active')
                                    ->label('Send this email')
                                    ->disabled(fn (?EmailTemplate $record): bool => self::isEssential($record))
                                    ->helperText(fn (?EmailTemplate $record): string => self::isEssential($record)
                                        ? 'Essential email: it is always sent and cannot be turned off.'
                                        : 'Optional email: turn off to stop sending it.'),
                                TextInput::make('name')
                                    ->label('Internal name')
                                    ->required()
                                    ->maxLength(120),
                                Textarea::make('description')
                                    ->label('Internal notes')
                                    ->rows(2)
                                    ->maxLength(500),
                            ]),
                        Section::make('Available variables')
                            ->schema([
                                Text::make(fn (?EmailTemplate $record): HtmlString => self::variablesHelp($record)),
                            ]),
                    ])->columnSpan(1),
                ]),
        ]);
    }

    public static function key(?EmailTemplate $record): ?EmailTemplateKey
    {
        return EmailTemplateKey::tryFrom((string) $record?->key);
    }

    public static function isEssential(?EmailTemplate $record): bool
    {
        return (bool) self::key($record)?->isEssential();
    }

    private static function variablesHelp(?EmailTemplate $record): HtmlString
    {
        $key = self::key($record);
        $names = $key ? SampleEmailVariables::names($key) : ['site_name', 'support_email', 'site_url'];

        $items = implode('', array_map(
            fn (string $name): string => '<li><code>{{'.e($name).'}}</code></li>',
            $names,
        ));

        $links = array_values(array_filter($names, fn (string $name): bool => str_ends_with($name, '_link')));
        $button = $links !== []
            ? '<p style="margin-top: .75rem;">Buttons: <code>{{button:'.e($links[0]).'|Button text}}</code> — works with any <code>…_link</code> variable and only for links to this site.</p>'
            : '';

        return new HtmlString('<ul style="display: grid; gap: .25rem;">'.$items.'</ul>'.$button
            .'<p style="margin-top: .75rem; color: var(--gray-500);">Lists such as <code>{{questions}}</code> become numbered lists. Unknown variables are left blank.</p>');
    }
}
