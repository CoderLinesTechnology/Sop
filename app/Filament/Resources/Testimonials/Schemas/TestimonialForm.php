<?php

namespace App\Filament\Resources\Testimonials\Schemas;

use App\Filament\Support\Catalogue\MediaUpload;
use App\Models\Testimonial;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TestimonialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Textarea::make('quote')
                    ->required()
                    ->rows(4)
                    ->maxLength(1000)
                    ->helperText('The customer’s words, without quotation marks.')
                    ->columnSpanFull(),
                TextInput::make('author_name')
                    ->label('Name')
                    ->required()
                    ->maxLength(120)
                    ->placeholder('e.g. Ama K.'),
                TextInput::make('author_detail')
                    ->label('Detail')
                    ->maxLength(160)
                    ->placeholder('e.g. MSc Data Science, University of Edinburgh'),
                Select::make('rating')
                    ->options([5 => '★★★★★ (5)', 4 => '★★★★ (4)', 3 => '★★★ (3)', 2 => '★★ (2)', 1 => '★ (1)'])
                    ->placeholder('No rating shown')
                    ->native(false),
                TextInput::make('display_order')
                    ->label('Display order')
                    ->integer()
                    ->default(fn (): int => (int) Testimonial::query()->max('display_order') + 1)
                    ->required(),
                MediaUpload::to('avatar_path', 'testimonials')
                    ->label('Photo')
                    ->avatar()
                    ->imageEditor()
                    ->circleCropper()
                    ->helperText('Optional square photo, only with the person’s consent.'),
                Toggle::make('is_published')->label('Published')->default(true),
                Toggle::make('is_featured')->label('Featured')->helperText('Featured testimonials are shown first.'),
            ]);
    }
}
