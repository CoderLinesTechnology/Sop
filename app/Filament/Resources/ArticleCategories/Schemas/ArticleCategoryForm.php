<?php

namespace App\Filament\Resources\ArticleCategories\Schemas;

use App\Filament\Support\Catalogue\IconOptions;
use App\Models\ArticleCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ArticleCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
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
                    ->label('URL slug')
                    ->required()
                    ->maxLength(120)
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['regex' => 'Use lowercase letters, numbers and single hyphens.']),
                Select::make('icon')
                    ->options(fn (?ArticleCategory $record): array => IconOptions::withCurrent(IconOptions::SERVICE_ICONS, $record?->icon))
                    ->default('book')
                    ->required()
                    ->native(false),
                TextInput::make('display_order')
                    ->label('Display order')
                    ->integer()
                    ->default(fn (): int => (int) ArticleCategory::query()->max('display_order') + 1)
                    ->required(),
                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive categories are hidden from the resources page filters.'),
            ]);
    }
}
