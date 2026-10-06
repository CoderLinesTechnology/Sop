<?php

namespace App\Filament\Resources\Testimonials\Pages;

use App\Filament\Resources\Testimonials\TestimonialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTestimonials extends ManageRecords
{
    protected static string $resource = TestimonialResource::class;

    protected ?string $subheading = 'Only publish genuine testimonials you have permission to use. Drag to reorder.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New testimonial'),
        ];
    }
}
