<?php

namespace App\Filament\Resources\Faqs\Pages;

use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Faqs\Tables\FaqsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFaqs extends ManageRecords
{
    protected static string $resource = FaqResource::class;

    protected ?string $subheading = 'Questions shown on the FAQ, home, resources and service pages. Drag to reorder.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New FAQ')
                ->mutateDataUsing(fn (array $data): array => FaqsTable::normalise($data)),
        ];
    }
}
