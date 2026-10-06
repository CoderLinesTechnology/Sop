<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\FieldSection;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\Format;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Models\UploadedFile;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Customer-provided content: form answers, uploaded files and the applicant
 * profile extracted from them. Visible only with customers.view.
 */
class OrderCustomerTabs
{
    /** @return list<Tab> */
    public static function make(): array
    {
        return [self::answers(), self::files(), self::profile()];
    }

    private static function answers(): Tab
    {
        return Tab::make('Answers')
            ->key('answers')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'answers'))
            ->visible(fn (Order $record): bool => AdminContext::allows('viewCustomerData', $record))
            ->schema(fn (Order $record): array => self::answerSections($record));
    }

    /** @return list<Section|EmptyState> */
    private static function answerSections(Order $order): array
    {
        $answers = $order->answers->sortBy('id');

        if ($answers->isEmpty()) {
            return [
                EmptyState::make('No answers recorded')
                    ->description('The customer has not submitted the order form yet.')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight),
            ];
        }

        $sections = [];
        foreach (FieldSection::cases() as $section) {
            $inSection = $answers->filter(fn (OrderAnswer $answer) => ($answer->section ?? FieldSection::Additional) === $section);
            if ($inSection->isEmpty()) {
                continue;
            }

            $sections[] = Section::make($section->getLabel())
                ->columns(['default' => 1, 'md' => 2])
                ->schema($inSection->map(fn (OrderAnswer $answer) => TextEntry::make('answer_'.$answer->id)
                    ->label($answer->label)
                    ->state(self::answerValue($order, $answer))
                    ->placeholder('Not answered')
                    ->columnSpan(in_array($answer->type, ['textarea', 'file'], true) || str_starts_with((string) $answer->field_key, 'followup_') ? 'full' : 1))
                    ->values()
                    ->all());
        }

        return $sections;
    }

    private static function answerValue(Order $order, OrderAnswer $answer): ?HtmlString
    {
        if ($answer->type === 'file') {
            $uuids = array_map('strval', (array) $answer->value);
            $names = $order->files->whereIn('uuid', $uuids)->pluck('original_name')->all();
            $text = $names ? implode(', ', $names).' (see Files)' : ($uuids ? count($uuids).' file(s) (see Files)' : '');
        } else {
            $text = $answer->displayValue();
        }

        return trim($text) === '' ? null : new HtmlString(nl2br(e($text)));
    }

    private static function files(): Tab
    {
        return Tab::make('Files')
            ->key('files')
            ->icon(Heroicon::OutlinedPaperClip)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'files'))
            ->visible(fn (Order $record): bool => AdminContext::allows('viewCustomerData', $record))
            ->schema([
                RepeatableEntry::make('files')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('File'),
                        TableColumn::make('Type'),
                        TableColumn::make('Size'),
                        TableColumn::make('Scan'),
                        TableColumn::make('Text extraction'),
                        TableColumn::make('Uploaded'),
                    ])
                    ->schema([
                        TextEntry::make('original_name')
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->url(fn (UploadedFile $record, $livewire): string => route('admin.support.orders.files.download', [
                                'order' => $livewire->getRecord()->public_id,
                                'file' => $record->uuid,
                            ]))
                            ->helperText(fn (UploadedFile $record): string => $record->purposeLabel().($record->field_key ? ' · '.$record->field_key : ''))
                            ->tooltip('Download (decrypted; the download is audited)'),
                        TextEntry::make('extension')
                            ->formatStateUsing(fn (?string $state): string => strtoupper((string) $state))
                            ->tooltip(fn (UploadedFile $record): ?string => $record->mime_type),
                        TextEntry::make('size_bytes')
                            ->formatStateUsing(fn ($state): string => Format::bytes((int) $state)),
                        TextEntry::make('scan_status')
                            ->badge()
                            ->tooltip(fn (UploadedFile $record): ?string => trim(($record->scan_engine ?? '').' '.($record->scan_result ?? '')) ?: null),
                        TextEntry::make('extraction_status')
                            ->badge()
                            ->helperText(fn (UploadedFile $record): ?string => collect([
                                $record->page_count ? $record->page_count.' '.str('page')->plural($record->page_count) : null,
                                $record->extracted_chars ? number_format($record->extracted_chars).' characters' : null,
                            ])->filter()->implode(' · ') ?: null),
                        TextEntry::make('created_at')
                            ->dateTime(Format::DATETIME),
                    ])
                    ->visible(fn (Order $record): bool => $record->files->isNotEmpty()),
                EmptyState::make('No files uploaded')
                    ->description('The customer did not upload any documents with this order.')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->visible(fn (Order $record): bool => $record->files->isEmpty()),
            ]);
    }

    private static function profile(): Tab
    {
        return Tab::make('Applicant profile')
            ->key('profile')
            ->icon(Heroicon::OutlinedIdentification)
            ->visible(fn (Order $record): bool => AdminContext::allows('viewCustomerData', $record))
            ->schema([
                Section::make('Applicant profile')
                    ->description('Facts extracted from the answers and uploaded documents, each with its source.')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            TextEntry::make('applicant.full_name')->label('Full name')->placeholder(Format::PLACEHOLDER),
                            TextEntry::make('applicant.model')->label('Extracted by')->fontFamily(FontFamily::Mono)->size(TextSize::Small)->placeholder(Format::PLACEHOLDER),
                            TextEntry::make('applicant.updated_at')->label('Last updated')->dateTime(Format::DATETIME),
                        ]),
                        TextEntry::make('applicant.missing_information')
                            ->label('Missing information')
                            ->state(fn (Order $record): array => array_values(array_map(
                                fn ($item) => is_array($item) ? (string) ($item['question'] ?? $item['field'] ?? json_encode($item)) : (string) $item,
                                (array) $record->applicant?->missing_information,
                            )))
                            ->bulleted()
                            ->color('warning')
                            ->placeholder('Nothing missing'),
                        TextEntry::make('applicant.source_files')
                            ->label('Source files')
                            ->state(fn (Order $record): array => array_values(array_map(
                                fn ($item) => is_array($item) ? (string) ($item['name'] ?? $item['uuid'] ?? json_encode($item)) : (string) $item,
                                (array) $record->applicant?->source_files,
                            )))
                            ->bulleted()
                            ->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('applicant.profile')
                            ->label('Profile')
                            ->state(fn (Order $record): HtmlString => Format::structured($record->applicant?->profile)),
                    ])
                    ->visible(fn (Order $record): bool => $record->applicant !== null),
                EmptyState::make('No applicant profile yet')
                    ->description('The profile is created during the first stage of AI processing.')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->visible(fn (Order $record): bool => $record->applicant === null),
            ]);
    }
}
