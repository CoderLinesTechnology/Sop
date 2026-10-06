<?php

namespace App\Filament\Resources\AiModelPrices\Pages;

use App\Filament\Resources\AiModelPrices\AiModelPriceResource;
use App\Models\AiModelPrice;
use App\Support\Audit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ManageAiModelPrices extends ManageRecords
{
    protected static string $resource = AiModelPriceResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('USD prices used to estimate the cost of every AI call. Prices change: check them against '
            .'<a href="https://openai.com/api/pricing/" target="_blank" rel="noopener noreferrer" style="text-decoration: underline;">OpenAI’s pricing page</a> '
            .'whenever you add a model or OpenAI announces new prices. Estimates drive per-order cost limits and the daily budget.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add model price')
                ->after(fn (AiModelPrice $record) => Audit::log('ai_model_price.created', $record, null, $record->only(['model', 'input_per_million', 'cached_input_per_million', 'output_per_million', 'web_search_per_call', 'is_active']))),
        ];
    }
}
