<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Filament\Resources\Promotions\PromotionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Facades\FilamentTimezone;

class ListPromotions extends ListRecords
{
    protected static string $resource = PromotionResource::class;

    public function getSubheading(): ?string
    {
        $timezone = FilamentTimezone::get();

        return 'It is now '.now()->setTimezone($timezone)->format('j M Y, H:i').' ('.$timezone.'), the time zone used for start and end times. Only one promotion applies per order: the largest discount wins, ties go to the higher priority.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New promotion'),
        ];
    }
}
