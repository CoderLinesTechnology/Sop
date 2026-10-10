<?php

namespace App\Filament\Resources\WritingSamples\Schemas;

use App\Domain\Ai\Samples\WritingSampleImporter;
use App\Enums\DocumentKind;
use App\Filament\Resources\WritingSamples\WritingSampleData;
use App\Filament\Support\Catalogue\DocumentFormatOptions;
use App\Filament\Support\Catalogue\IconOptions;
use App\Models\WritingSample;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class WritingSampleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('About this sample')
                ->description('Each order is shown a few active samples of its document type, the closest match on field of study, degree level and destination country first.')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(160)
                        ->helperText('For your reference only; never sent to the AI.'),
                    Select::make('document_kind')
                        ->label('Document type')
                        ->options(DocumentKind::class)
                        ->required()
                        ->native(false),
                    Select::make('degree_level')
                        ->label('Degree level')
                        ->options(fn (?WritingSample $record): array => IconOptions::withCurrent(DocumentFormatOptions::DEGREE_LEVELS, $record?->degree_level))
                        ->placeholder('Any level'),
                    TextInput::make('field_of_study')
                        ->label('Field of study')
                        ->maxLength(120)
                        ->placeholder('e.g. Computer Science'),
                    Select::make('country_code')
                        ->label('Destination country')
                        ->options(fn (): array => DocumentFormatOptions::countries())
                        ->searchable()
                        ->placeholder('Any country'),
                    TextInput::make('priority')
                        ->integer()
                        ->default(0)
                        ->minValue(-100)
                        ->maxValue(100)
                        ->required()
                        ->helperText('Among equally relevant samples, higher priority is used first.'),
                    Textarea::make('notes')
                        ->label('What makes this a strong example?')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull()
                        ->helperText('Shown to the AI with the sample, e.g. "Opens with one concrete lab moment; links each module to past work."'),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive samples are kept but never shown to the AI.'),
                ])
                ->columns(2),

            Section::make('Document')
                ->visibleOn('create')
                ->description('Upload a finished document or paste its text. Only the text is kept: the file itself is discarded, and email addresses, phone numbers and links are removed automatically. You can review and edit the text after saving.')
                ->schema([
                    Radio::make('source')
                        ->hiddenLabel()
                        ->options([
                            WritingSample::SOURCE_UPLOAD => 'Upload a file (PDF, DOCX or TXT)',
                            WritingSample::SOURCE_PASTED => 'Paste the text',
                        ])
                        ->default(WritingSample::SOURCE_UPLOAD)
                        ->inline()
                        ->live()
                        ->required(),
                    FileUpload::make('file')
                        ->label('File')
                        ->acceptedFileTypes(WritingSampleData::ACCEPTED_TYPES)
                        ->rule('extensions:pdf,docx,txt')
                        ->maxSize(WritingSampleData::MAX_UPLOAD_KB)
                        ->storeFiles(false)
                        ->visible(fn (Get $get): bool => $get('source') !== WritingSample::SOURCE_PASTED)
                        ->required(fn (Get $get): bool => $get('source') !== WritingSample::SOURCE_PASTED),
                    Textarea::make('pasted_text')
                        ->label('Text')
                        ->rows(16)
                        ->maxLength(WritingSampleImporter::MAX_CHARS)
                        ->visible(fn (Get $get): bool => $get('source') === WritingSample::SOURCE_PASTED)
                        ->required(fn (Get $get): bool => $get('source') === WritingSample::SOURCE_PASTED),
                    self::rightsConfirmation('rights_confirmed'),
                ]),

            Section::make('Text the AI studies')
                ->visibleOn('edit')
                ->description('Remove anything that could identify the author or another person (names, schools, employers, places). Email addresses, phone numbers and links were removed automatically.')
                ->schema([
                    TextEntry::make('import_summary')
                        ->hiddenLabel()
                        ->color('gray')
                        ->state(fn (?WritingSample $record): ?string => $record ? WritingSampleData::summary($record) : null),
                    Textarea::make('content')
                        ->hiddenLabel()
                        ->rows(24)
                        ->maxLength(WritingSampleImporter::MAX_CHARS)
                        ->required(),
                    FileUpload::make('replacement_file')
                        ->label('Replace with a new file')
                        ->helperText('Replaces the text above when you save.')
                        ->acceptedFileTypes(WritingSampleData::ACCEPTED_TYPES)
                        ->rule('extensions:pdf,docx,txt')
                        ->maxSize(WritingSampleData::MAX_UPLOAD_KB)
                        ->storeFiles(false)
                        ->live(),
                    self::rightsConfirmation('replacement_rights_confirmed')
                        ->visible(fn (Get $get): bool => filled($get('replacement_file'))),
                ]),
        ]);
    }

    /** Confirmation required for every document added as a sample (validated only while visible). */
    private static function rightsConfirmation(string $name): Checkbox
    {
        return Checkbox::make($name)
            ->label("This document is anonymised, or its author has agreed to it being used as a writing sample. It does not belong to a customer who hasn't agreed.")
            ->accepted()
            ->validationMessages(['accepted' => 'Confirm that the document may be used as a writing sample.']);
    }
}
