<?php

namespace App\Filament\Widgets\Analytics;

use App\Enums\Permission;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Concerns\ReadsMetricsRange;
use App\Filament\Support\Operations\Format;
use App\Models\AnalyticsEvent;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Visitor → paid conversion funnel from first-party analytics events. */
class ConversionFunnel extends TableWidget
{
    use ReadsMetricsRange;

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return AdminContext::can(Permission::AnalyticsView);
    }

    public function table(Table $table): Table
    {
        $metrics = $this->metrics();

        return $table
            ->heading('Conversion funnel')
            ->description('Unique visitors per step (unique orders once an order exists), last '.$this->rangeLabel().'.')
            ->records(fn (): array => collect($metrics->funnel())->keyBy('event')->all())
            ->columns([
                TextColumn::make('label')
                    ->label('Step')
                    ->weight(fn (array $record): ?FontWeight => $record['event'] === AnalyticsEvent::PAYMENT_SUCCESS ? FontWeight::SemiBold : null)
                    ->color(fn (array $record): ?string => $record['event'] === AnalyticsEvent::PAYMENT_FAILED ? 'danger' : null),
                TextColumn::make('count')->label('Count')->numeric()->alignEnd(),
                TextColumn::make('step_rate')
                    ->label('From previous step')
                    ->formatStateUsing(fn ($state): string => Format::percent($state === null ? null : (float) $state))
                    ->placeholder(Format::PLACEHOLDER)
                    ->alignEnd(),
                TextColumn::make('overall_rate')
                    ->label('From visitors')
                    ->formatStateUsing(fn ($state): string => Format::percent($state === null ? null : (float) $state, 2))
                    ->placeholder(Format::PLACEHOLDER)
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
