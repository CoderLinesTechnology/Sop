<?php

namespace App\Filament\Resources\Promotions\Schemas;

use App\Filament\Support\Operations\DiscountFields;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;

class PromotionForm
{
    public static function configure(Schema $schema): Schema
    {
        $timezone = FilamentTimezone::get();

        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->components([
                Section::make('Promotion')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(160)
                            ->helperText('Internal name, e.g. "Back to school 2026".'),
                        TextInput::make('label')
                            ->label('Badge label')
                            ->maxLength(80)
                            ->placeholder('LIMITED-TIME OFFER')
                            ->helperText('Shown to customers next to the price.'),
                        Textarea::make('description')
                            ->label('Description')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('priority')
                            ->label('Priority')
                            ->integer()
                            ->default(0)
                            ->required()
                            ->helperText('Breaks ties between promotions with the same discount (higher wins).'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false),
                    ]),
                Section::make('Discount')
                    ->description('Either a percentage or a fixed amount. Amounts are entered in major units (e.g. 15.50).')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema(DiscountFields::make(capHelp: 'Caps a percentage discount. Leave empty for no cap.')),
                Section::make('Schedule')
                    ->description("Server time ({$timezone}). Leave empty to start now or never end.")
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label('Starts')
                            ->seconds(false)
                            ->helperText(fn (): string => 'Now: '.now()->setTimezone(FilamentTimezone::get())->format('j M Y, H:i')),
                        DateTimePicker::make('ends_at')
                            ->label('Ends')
                            ->seconds(false)
                            ->after('starts_at')
                            ->required(fn (Get $get): bool => (bool) $get('show_countdown')),
                        Toggle::make('show_countdown')
                            ->label('Show a countdown')
                            ->helperText('Displays the time left until the end. Requires an end time.')
                            ->live()
                            ->columnSpanFull(),
                    ]),
                Section::make('Services and banner')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Toggle::make('applies_to_all_services')
                            ->label('All services')
                            ->default(true)
                            ->live()
                            ->columnSpanFull(),
                        Select::make('services')
                            ->label('Services')
                            ->relationship('services', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required(fn (Get $get): bool => ! $get('applies_to_all_services'))
                            ->visible(fn (Get $get): bool => ! $get('applies_to_all_services'))
                            ->columnSpanFull(),
                        Toggle::make('show_banner')
                            ->label('Show the site-wide banner')
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('banner_text')
                            ->label('Banner text')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) $get('show_banner'))
                            ->visible(fn (Get $get): bool => (bool) $get('show_banner'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
