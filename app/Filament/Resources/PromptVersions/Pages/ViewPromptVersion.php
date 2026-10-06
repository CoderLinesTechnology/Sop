<?php

namespace App\Filament\Resources\PromptVersions\Pages;

use App\Filament\Resources\PromptVersions\PromptVersionResource;
use App\Filament\Resources\PromptVersions\Tables\PromptVersionsTable;
use App\Filament\Support\Catalogue\PromptActivation;
use App\Models\PromptVersion;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property PromptVersion $record
 */
class ViewPromptVersion extends ViewRecord
{
    protected static string $resource = PromptVersionResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return match ($this->record->status) {
            PromptVersion::STATUS_ACTIVE => 'This version is in production. It can no longer be edited; create a new draft to change it.',
            PromptVersion::STATUS_ARCHIVED => 'This version was replaced. It can no longer be edited, but it can be re-activated to roll back.',
            default => 'Draft — not used by the AI until it is activated.',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            PromptActivation::action(),
            PromptVersionsTable::compareAction(),
            PromptVersionsTable::newDraftAction(),
        ];
    }
}
