<?php

namespace App\Filament\Resources\Faqs\Schemas;

use App\Models\Faq;
use App\Models\Service;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('question')
                    ->required()
                    ->maxLength(500)
                    ->columnSpanFull(),
                MarkdownEditor::make('answer')
                    ->required()
                    ->maxLength(5000)
                    ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList']])
                    ->minHeight('8rem')
                    ->columnSpanFull(),
                Select::make('scope')
                    ->label('Shown on')
                    ->options(Faq::SCOPES)
                    ->default('general')
                    ->required()
                    ->live()
                    ->native(false),
                Select::make('service_id')
                    ->label('Service')
                    ->options(fn (): array => Service::query()->ordered()->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(fn (Get $get): bool => $get('scope') === 'service')
                    ->visible(fn (Get $get): bool => $get('scope') === 'service'),
                TextInput::make('display_order')
                    ->label('Display order')
                    ->integer()
                    ->default(fn (): int => (int) Faq::query()->max('display_order') + 1)
                    ->required(),
                Toggle::make('is_published')
                    ->label('Published')
                    ->default(true)
                    ->inline(false),
            ]);
    }
}
