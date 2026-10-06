<?php

namespace App\Filament\Resources\AiModelPrices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;

class AiModelPriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Callout::make('Check the official price list')
                    ->description('Copy prices from OpenAI’s pricing page (openai.com/api/pricing) exactly, per 1 million tokens.')
                    ->info()
                    ->columnSpanFull(),
                TextInput::make('model')
                    ->label('Model id')
                    ->required()
                    ->maxLength(80)
                    ->regex('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/')
                    ->unique(ignoreRecord: true)
                    ->placeholder('e.g. gpt-6.1-sol')
                    ->helperText('Exactly as used in API calls and workflows.')
                    ->columnSpanFull(),
                self::price('input_per_million', 'Input tokens')->required(),
                self::price('cached_input_per_million', 'Cached input tokens')->helperText('Blank = same as input.'),
                self::price('output_per_million', 'Output tokens')->required()->helperText('Reasoning tokens are billed as output.'),
                TextInput::make('web_search_per_call')
                    ->label('Web search, per call')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.000001)
                    ->prefix('$')
                    ->default(0)
                    ->required(),
                TextInput::make('notes')->maxLength(255)->placeholder('e.g. Checked 6 Oct 2026')->columnSpanFull(),
                Toggle::make('is_active')->label('Active')->default(true),
            ]);
    }

    private static function price(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(10000)
            ->step(0.0001)
            ->prefix('$')
            ->suffix('per 1M');
    }
}
