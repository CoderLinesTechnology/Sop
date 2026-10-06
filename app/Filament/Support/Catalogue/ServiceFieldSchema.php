<?php

namespace App\Filament\Support\Catalogue;

use App\Enums\FieldMapping;
use App\Enums\FieldSection;
use App\Enums\FieldType;
use App\Enums\RequirementLevel;
use App\Models\ServiceField;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The no-code order-form builder: one repeater item per question or upload
 * slot of a service (App\Models\ServiceField). See ServiceField for the JSON
 * shapes of options / validation / show_when.
 */
final class ServiceFieldSchema
{
    /** Extensions an upload slot may accept (subset of what the upload validator supports). */
    public const FILE_EXTENSIONS = ['pdf' => 'PDF', 'docx' => 'Word (.docx)', 'txt' => 'Plain text', 'jpg' => 'JPEG image', 'png' => 'PNG image'];

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const LENGTH_TYPES = [FieldType::Text, FieldType::Textarea, FieldType::Email, FieldType::Phone, FieldType::Url];

    public static function repeater(): Repeater
    {
        return Repeater::make('fields')
            ->label('Questions & upload slots')
            ->hiddenLabel()
            ->relationship('fields')
            ->orderColumn('display_order')
            ->schema(self::itemSchema())
            ->columns(2)
            ->default(self::starterFields())
            ->addActionLabel('Add question or upload slot')
            ->collapsible()
            ->collapsed(fn (string $operation): bool => $operation === 'edit')
            ->cloneable()
            ->reorderableWithButtons()
            ->itemLabel(fn (array $state): string => self::itemLabel($state))
            ->deleteAction(fn ($action) => $action->requiresConfirmation()
                ->modalHeading('Remove this question?')
                ->modalDescription('Existing orders keep the answers they already submitted. New orders will no longer ask this question.'))
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => ServiceFieldNormalizer::normalize($data))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => ServiceFieldNormalizer::normalize($data));
    }

    /** @return array<Component> */
    public static function itemSchema(): array
    {
        return [
            TextInput::make('label')
                ->label('Question / label')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state, ?Model $record): void {
                    // Generate the key for new questions only; existing keys are never changed silently.
                    if ($record instanceof ServiceField && $record->exists) {
                        return;
                    }
                    $currentKey = (string) $get('key');
                    if ($currentKey === '' || $currentKey === self::keyFrom($old)) {
                        $set('key', self::keyFrom($state));
                    }
                })
                ->placeholder('e.g. Why are you interested in this field?'),

            TextInput::make('key')
                ->label('Field key')
                ->required()
                ->maxLength(60)
                ->regex(self::KEY_PATTERN)
                ->distinct()
                ->live(onBlur: true)
                ->validationMessages([
                    'regex' => 'Use lowercase letters, numbers and underscores, starting with a letter (e.g. why_programme).',
                    'distinct' => 'Each question needs its own key.',
                ])
                ->helperText(fn (?Model $record): string => $record instanceof ServiceField && $record->exists
                    ? 'Internal name. Past orders keep their answers if you rename it, but other questions that refer to this key must be updated too.'
                    : 'Internal name used to store the answer. Generated from the label.'),

            Select::make('type')
                ->label('Answer type')
                ->options(FieldType::class)
                ->required()
                ->default(FieldType::Textarea->value)
                ->live()
                ->native(false),

            Select::make('section')
                ->label('Form section')
                ->options(FieldSection::class)
                ->required()
                ->default(FieldSection::Application->value)
                ->helperText('Upload slots always appear in the "Upload what you have" group.'),

            Select::make('requirement')
                ->label('Requirement')
                ->options(RequirementLevel::class)
                ->required()
                ->default(RequirementLevel::Optional->value)
                ->live(),

            ToggleButtons::make('width')
                ->label('Width on the form')
                ->options(['full' => 'Full width', 'half' => 'Half width'])
                ->default('full')
                ->inline()
                ->required(),

            Select::make('maps_to')
                ->label('Maps to order detail')
                ->options(FieldMapping::class)
                ->placeholder('Not mapped')
                ->distinct()
                ->live()
                ->helperText('Makes the answer available to the requirements engine, emails, file names and admin search. Each detail can be mapped once.'),

            Toggle::make('is_active')
                ->label('Shown on the order form')
                ->default(true)
                ->live()
                ->inline(false),

            Textarea::make('help_text')
                ->label('Help text')
                ->rows(2)
                ->maxLength(1000)
                ->helperText('Shown under the question.'),

            TextInput::make('placeholder')
                ->label('Placeholder')
                ->maxLength(255)
                ->hidden(fn (Get $get): bool => in_array(self::type($get('type')), [FieldType::File, FieldType::Checkbox, FieldType::Radio, FieldType::MultiSelect], true)),

            // ----- Choices (dropdown / radio / multiple choice)
            Repeater::make('options.choices')
                ->label('Choices')
                ->table([
                    TableColumn::make('Stored value')->markAsRequired(),
                    TableColumn::make('Label shown to the customer')->markAsRequired(),
                ])
                ->schema([
                    TextInput::make('value')->required()->maxLength(100)->distinct(),
                    TextInput::make('label')->required()->maxLength(255),
                ])
                ->compact()
                ->minItems(1)
                ->required()
                ->addActionLabel('Add choice')
                ->reorderableWithButtons()
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => (bool) self::type($get('type'))?->hasOptions()),

            // ----- Upload slot settings
            Fieldset::make('Upload settings')
                ->schema([
                    CheckboxList::make('options.accept')
                        ->label('Accepted file types')
                        ->options(self::FILE_EXTENSIONS)
                        ->default(array_keys(self::FILE_EXTENSIONS))
                        ->required()
                        ->columns(3)
                        ->helperText('Never more than the global allowed types in Settings → Orders.')
                        ->columnSpanFull(),
                    TextInput::make('options.max_files')
                        ->label('Maximum files')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(10)
                        ->default(1)
                        ->required(),
                    Select::make('options.purpose')
                        ->label('What is uploaded here?')
                        ->options(ServiceField::UPLOAD_PURPOSES)
                        ->default('other')
                        ->required()
                        ->helperText('Tells the AI how to read the file (e.g. a CV is mined for experience).'),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::type($get('type')) === FieldType::File),

            // ----- Validation
            Fieldset::make('Answer length')
                ->schema([
                    TextInput::make('validation.min_length')->label('Minimum characters')->integer()->minValue(0)->maxValue(20000),
                    TextInput::make('validation.max_length')
                        ->label('Maximum characters')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(20000)
                        ->rules([FormRules::notLessThan('validation.min_length', 'The maximum cannot be lower than the minimum.')])
                        ->helperText('Blank = 255 for short text, 5,000 for long text.'),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => in_array(self::type($get('type')), self::LENGTH_TYPES, true)),

            Fieldset::make('Allowed numbers')
                ->schema([
                    TextInput::make('validation.min')->label('Minimum')->numeric(),
                    TextInput::make('validation.max')
                        ->label('Maximum')
                        ->numeric()
                        ->rules([FormRules::notLessThan('validation.min', 'The maximum cannot be lower than the minimum.')]),
                ])
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => self::type($get('type')) === FieldType::Number),

            // ----- Behaviour
            Fieldset::make('Behaviour')
                ->schema([
                    Select::make('optional_when_upload')
                        ->label('Optional when this upload is provided')
                        ->placeholder('Never')
                        ->options(fn (Get $get): array => self::siblingOptions($get('../../fields'), $get('key'), onlyFiles: true))
                        ->helperText('e.g. background questions become optional when the customer uploads a CV.')
                        ->visible(fn (Get $get): bool => self::type($get('type')) !== FieldType::File
                            && self::requirement($get('requirement')) !== RequirementLevel::Optional),
                    Grid::make(2)
                        ->schema([
                            Select::make('show_when.field')
                                ->label('Only show when')
                                ->placeholder('Always show')
                                ->options(fn (Get $get): array => self::siblingOptions($get('../../fields'), $get('key'), excludeFiles: true))
                                ->live(),
                            TextInput::make('show_when.equals')
                                ->label('…has the value')
                                ->maxLength(255)
                                ->required(fn (Get $get): bool => filled($get('show_when.field')))
                                ->datalist(fn (Get $get): array => self::choiceValues($get('../../fields'), $get('show_when.field')))
                                ->visible(fn (Get $get): bool => filled($get('show_when.field'))),
                        ])
                        ->columnSpanFull(),
                    Textarea::make('ai_hint')
                        ->label('Note for the AI')
                        ->rows(2)
                        ->maxLength(2000)
                        ->helperText('How the writer should use this answer (e.g. "Primary evidence for the narrative. Never embellish.").')
                        ->columnSpanFull(),
                ])
                ->columns(1)
                ->columnSpanFull(),
        ];
    }

    public static function itemLabel(array $state): string
    {
        $label = trim((string) ($state['label'] ?? '')) ?: 'New question';
        $type = self::type($state['type'] ?? null);
        $parts = [Str::limit($label, 70)];

        if ($type) {
            $parts[] = $type->getLabel();
        }
        if (self::requirement($state['requirement'] ?? null) === RequirementLevel::Required) {
            $parts[] = 'required';
        }
        if (($mapping = self::mapping($state['maps_to'] ?? null)) !== null) {
            $parts[] = '→ '.$mapping->getLabel();
        }
        if (array_key_exists('is_active', $state) && ! $state['is_active']) {
            $parts[] = 'hidden';
        }

        return implode(' · ', $parts);
    }

    public static function keyFrom(?string $label): string
    {
        $key = (string) Str::of((string) $label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
        $key = mb_substr($key, 0, 60);

        if ($key !== '' && ! preg_match('/^[a-z]/', $key)) {
            $key = mb_substr('q_'.$key, 0, 60);
        }

        return rtrim($key, '_');
    }

    /**
     * Keys of the other questions in the form, labelled for a select.
     *
     * @return array<string, string>
     */
    public static function siblingOptions(mixed $fields, mixed $selfKey, bool $onlyFiles = false, bool $excludeFiles = false): array
    {
        $options = [];

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (! is_array($field)) {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || $key === (string) $selfKey) {
                continue;
            }
            $isFile = self::type($field['type'] ?? null) === FieldType::File;
            if (($onlyFiles && ! $isFile) || ($excludeFiles && $isFile)) {
                continue;
            }
            $options[$key] = trim((string) ($field['label'] ?? '')) !== '' ? $field['label'].' ('.$key.')' : $key;
        }

        return $options;
    }

    /** @return list<string> */
    public static function choiceValues(mixed $fields, mixed $key): array
    {
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field) && (string) ($field['key'] ?? '') === (string) $key) {
                $type = self::type($field['type'] ?? null);
                if ($type === FieldType::Checkbox) {
                    return ['1', '0'];
                }

                return array_values(array_filter(array_map(
                    fn ($choice) => is_array($choice) ? (string) ($choice['value'] ?? '') : (string) $choice,
                    (array) data_get($field, 'options.choices', []),
                )));
            }
        }

        return [];
    }

    /** The questions every new service starts with (removable). */
    public static function starterFields(): array
    {
        $field = fn (array $data): array => $data + [
            'section' => FieldSection::Details->value,
            'requirement' => RequirementLevel::Required->value,
            'width' => 'half',
            'is_active' => true,
            'help_text' => null,
            'placeholder' => null,
        ];

        return [
            $field(['key' => 'full_name', 'label' => 'Full name', 'type' => FieldType::Text->value, 'maps_to' => FieldMapping::CustomerName->value, 'help_text' => 'As it should appear on your document.', 'validation' => ['max_length' => 120]]),
            $field(['key' => 'email', 'label' => 'Email address', 'type' => FieldType::Email->value, 'maps_to' => FieldMapping::Email->value, 'help_text' => 'Your finished document will be sent here.']),
            $field(['key' => 'cv', 'label' => 'CV / Resume', 'type' => FieldType::File->value, 'section' => FieldSection::Application->value, 'requirement' => RequirementLevel::Optional->value, 'width' => 'full', 'maps_to' => null, 'help_text' => 'We extract your education, experience and achievements automatically.', 'options' => ['accept' => array_keys(self::FILE_EXTENSIONS), 'max_files' => 1, 'purpose' => 'cv']]),
        ];
    }

    public static function type(mixed $value): ?FieldType
    {
        return $value instanceof FieldType ? $value : FieldType::tryFrom(self::scalar($value));
    }

    public static function requirement(mixed $value): ?RequirementLevel
    {
        return $value instanceof RequirementLevel ? $value : RequirementLevel::tryFrom(self::scalar($value));
    }

    public static function mapping(mixed $value): ?FieldMapping
    {
        return $value instanceof FieldMapping ? $value : FieldMapping::tryFrom(self::scalar($value));
    }

    private static function scalar(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (is_scalar($value) ? (string) $value : '');
    }
}
