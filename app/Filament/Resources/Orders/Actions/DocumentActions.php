<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Domain\Delivery\DocumentDelivery;
use App\Domain\Documents\DocumentAdminOperations;
use App\Domain\Documents\DocumentModel;
use App\Filament\Resources\Orders\OrderInsights;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\OperationsAudit;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\Revision;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Manual document overrides (documents.manage) through
 * DocumentAdminOperations — each creates a new, audited document version —
 * and delivery of a chosen version through DocumentDelivery.
 */
final class DocumentActions
{
    private const MAX_UPLOAD_KB = 15 * 1024;

    private const DOCX_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ];

    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::uploadCorrected(),
            self::replaceFile(),
            self::rerender(),
            self::editText(),
            self::deliverVersion(),
        ];
    }

    public static function uploadCorrected(): Action
    {
        return Action::make('uploadCorrectedDocument')
            ->label('Upload corrected document')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->authorize('manageDocuments')
            ->visible(fn (Order $record): bool => OrderInsights::isSubmitted($record))
            ->modalHeading('Upload a corrected document')
            ->modalDescription('Creates a new document version from a finished DOCX (and optionally the matching PDF). Without a PDF, the PDF is rendered from the DOCX text.')
            ->schema(fn (Order $record): array => [
                self::docxUpload('docx', 'DOCX')->required(),
                FileUpload::make('pdf')
                    ->label('PDF (optional)')
                    ->acceptedFileTypes(['application/pdf'])
                    ->rule('extensions:pdf')
                    ->maxSize(self::MAX_UPLOAD_KB)
                    ->storeFiles(false),
                Select::make('revision_id')
                    ->label('Revision')
                    ->options($record->revisions->mapWithKeys(fn (Revision $revision) => [$revision->id => 'Revision #'.$revision->number.' · '.$revision->status->getLabel()])->all())
                    ->placeholder('Not linked to a revision')
                    ->visible($record->revisions->isNotEmpty()),
                self::deliverToggle(),
            ])
            ->modalSubmitActionLabel('Upload')
            ->action(function (array $data, Order $record, Action $action): void {
                $revision = filled($data['revision_id'] ?? null) ? $record->revisions()->whereKey($data['revision_id'])->first() : null;

                self::createVersion(
                    $record,
                    $data,
                    'document.uploaded',
                    fn () => app(DocumentAdminOperations::class)->uploadFinal(
                        $record,
                        self::uploaded($data['docx'] ?? null, 'docx') ?? throw new RuntimeException('Choose a DOCX file to upload.'),
                        self::uploaded($data['pdf'] ?? null, 'pdf'),
                        AdminContext::require(),
                        $revision,
                    ),
                    $action,
                );
            });
    }

    public static function replaceFile(): Action
    {
        return Action::make('replaceDocumentFile')
            ->label('Replace PDF or DOCX')
            ->icon(Heroicon::OutlinedDocumentArrowUp)
            ->authorize('manageDocuments')
            ->visible(fn (Order $record): bool => $record->documentVersions->isNotEmpty())
            ->modalHeading('Replace a file of a version')
            ->modalDescription('Creates a new version that reuses the chosen version and swaps in the uploaded PDF or DOCX.')
            ->schema(fn (Order $record): array => [
                self::versionSelect($record),
                Radio::make('format')
                    ->label('File to replace')
                    ->options(['pdf' => 'PDF', 'docx' => 'DOCX'])
                    ->default('pdf')
                    ->inline()
                    ->live()
                    ->required(),
                FileUpload::make('file')
                    ->label('Replacement file')
                    ->acceptedFileTypes(fn (Get $get): array => $get('format') === 'docx' ? self::DOCX_TYPES : ['application/pdf'])
                    ->rule(fn (Get $get): string => 'extensions:'.($get('format') === 'docx' ? 'docx' : 'pdf'))
                    ->maxSize(self::MAX_UPLOAD_KB)
                    ->storeFiles(false)
                    ->required(),
                self::deliverToggle(),
            ])
            ->modalSubmitActionLabel('Replace file')
            ->action(function (array $data, Order $record, Action $action): void {
                $version = self::version($record, $data['version_id'] ?? null);
                $format = $data['format'] === 'docx' ? 'docx' : 'pdf';

                self::createVersion(
                    $record,
                    $data,
                    'document.file_replaced',
                    fn () => app(DocumentAdminOperations::class)->replaceFile(
                        $version,
                        $format,
                        self::uploaded($data['file'] ?? null, $format) ?? throw new RuntimeException('Choose a file to upload.'),
                        AdminContext::require(),
                    ),
                    $action,
                    ['source_version' => $version->uuid, 'format' => $format],
                );
            });
    }

    public static function rerender(): Action
    {
        return Action::make('rerenderDocument')
            ->label('Re-render with template')
            ->icon(Heroicon::OutlinedPaintBrush)
            ->authorize('manageDocuments')
            ->visible(fn (Order $record): bool => $record->documentVersions->isNotEmpty())
            ->modalHeading('Re-render a version')
            ->modalDescription('Renders the chosen version’s approved text again (PDF and DOCX) with a formatting template, as a new version.')
            ->schema(fn (Order $record): array => [
                self::versionSelect($record),
                Select::make('template_id')
                    ->label('Formatting template')
                    ->options(fn (): array => DocumentTemplate::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('Keep the version’s template')
                    ->searchable(),
                self::deliverToggle(),
            ])
            ->modalSubmitActionLabel('Re-render')
            ->action(function (array $data, Order $record, Action $action): void {
                $version = self::version($record, $data['version_id'] ?? null);
                $template = filled($data['template_id'] ?? null)
                    ? DocumentTemplate::query()->where('is_active', true)->find($data['template_id'])
                    : null;

                self::createVersion(
                    $record,
                    $data,
                    'document.rerendered',
                    fn () => app(DocumentAdminOperations::class)->rerender($version, $template, AdminContext::require()),
                    $action,
                    ['source_version' => $version->uuid, 'template_id' => $template?->id],
                );
            });
    }

    public static function editText(): Action
    {
        return Action::make('editDocumentText')
            ->label('Edit text')
            ->icon(Heroicon::OutlinedPencil)
            ->authorize('manageDocuments')
            ->visible(fn (Order $record): bool => self::editableVersions($record) !== [])
            ->modalHeading('Edit document text')
            ->modalDescription('Saves the edited text as a new version; the PDF and DOCX are rendered from it, so both files always carry the same text.')
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(fn (Order $record): array => self::textFormData(OrderInsights::versions($record)->first(fn (DocumentVersion $v) => filled($v->content))))
            ->schema(fn (Order $record): array => [
                Select::make('version_id')
                    ->label('Start from version')
                    ->options(self::editableVersions($record))
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set) use ($record): void {
                        $version = filled($state) ? $record->documentVersions()->whereKey($state)->first() : null;
                        foreach (self::textFormData($version) as $key => $value) {
                            if ($key !== 'version_id') {
                                $set($key, $value);
                            }
                        }
                    }),
                TextInput::make('title')->label('Title')->required()->maxLength(300),
                TextInput::make('subtitle')->label('Subtitle')->maxLength(300),
                TextInput::make('applicant_name')->label('Applicant name')->maxLength(200),
                Repeater::make('blocks')
                    ->label('Body')
                    ->schema([
                        Select::make('type')
                            ->options([
                                'heading' => 'Heading',
                                'paragraph' => 'Paragraph',
                                'salutation' => 'Salutation',
                                'closing' => 'Closing',
                                'signature' => 'Signature',
                            ])
                            ->default('paragraph')
                            ->required()
                            ->native(false),
                        Textarea::make('text')->required()->rows(4)->maxLength(20000),
                    ])
                    ->columns(['default' => 1])
                    ->minItems(1)
                    ->required()
                    ->addActionLabel('Add block')
                    ->collapsible(),
                self::deliverToggle(),
            ])
            ->modalSubmitActionLabel('Save as new version')
            ->action(function (array $data, Order $record, Action $action): void {
                $version = self::version($record, $data['version_id'] ?? null);
                $content = (array) $version->content;

                $model = DocumentModel::fromArray([
                    'title' => $data['title'],
                    'subtitle' => $data['subtitle'] ?? null,
                    'applicant_name' => $data['applicant_name'] ?? null,
                    'date' => $content['date'] ?? null,
                    'language_variant' => $content['language_variant'] ?? ($version->language_variant ?: 'en-GB'),
                    'blocks' => array_values($data['blocks'] ?? []),
                ]);

                self::createVersion(
                    $record,
                    $data,
                    'document.text_edited',
                    fn () => app(DocumentAdminOperations::class)->editText($version, $model, AdminContext::require()),
                    $action,
                    ['source_version' => $version->uuid],
                );
            });
    }

    public static function deliverVersion(): Action
    {
        return Action::make('deliverVersion')
            ->label('Deliver a version')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->authorize('manageDocuments')
            ->visible(fn (Order $record): bool => OrderInsights::isSubmitted($record) && OrderInsights::deliverableVersions($record)->isNotEmpty())
            ->modalHeading('Deliver a version to the customer')
            ->modalDescription(fn (Order $record): string => 'Emails the selected version (PDF and DOCX) to '.$record->email.'. The order becomes Delivered once the email provider accepts the message. Only versions that passed file validation can be delivered.')
            ->schema(fn (Order $record): array => [
                Select::make('version_id')
                    ->label('Version')
                    ->options(OrderInsights::versionOptions(OrderInsights::deliverableVersions($record)))
                    ->default(OrderInsights::deliverableVersions($record)->first()?->id)
                    ->required()
                    ->native(false),
            ])
            ->modalSubmitActionLabel('Deliver')
            ->action(function (array $data, Order $record, Action $action): void {
                $version = self::version($record, $data['version_id'] ?? null);

                ActionRunner::run(
                    fn () => self::deliver($record, $version),
                    'Sending the delivery email',
                    "Couldn't deliver the version",
                    'v'.$version->version_number.' is on its way to '.$record->email.'.',
                    keepOpen: $action,
                );
            });
    }

    /**
     * Run a version-creating operation, then optionally deliver the result.
     * A failed delivery is reported separately: the new version is kept.
     *
     * @param  \Closure(): DocumentVersion  $operation
     */
    private static function createVersion(Order $order, array $data, string $auditAction, \Closure $operation, Action $action, array $meta = []): void
    {
        $created = null;

        $succeeded = ActionRunner::run(
            function () use ($order, $operation, $auditAction, $meta, &$created) {
                $created = OperationsAudit::ensure($auditAction, $order, $operation, meta: $meta);

                if (! $created instanceof DocumentVersion) {
                    throw new RuntimeException('The document service did not return a new version.');
                }

                return $created;
            },
            'New document version created',
            "Couldn't create the new version",
            fn (DocumentVersion $version): string => 'v'.$version->version_number.' · QA '.($version->qa_status ?: 'not checked'),
            keepOpen: $action,
        );

        if ($succeeded && ($data['deliver'] ?? false) && $created) {
            ActionRunner::run(
                fn () => self::deliver($order, $created),
                'Sending the delivery email',
                'The new version was created but not delivered',
            );
        }
    }

    private static function deliver(Order $order, DocumentVersion $version): mixed
    {
        return OperationsAudit::ensure(
            'order.document_delivered',
            $order,
            fn () => app(DocumentDelivery::class)->deliver($order, $version, $version->revision),
            meta: ['document_version' => $version->uuid, 'version' => $version->version_number],
        );
    }

    /** A version of this order (never another order's), chosen in a form. */
    private static function version(Order $order, mixed $id): DocumentVersion
    {
        $version = filled($id) ? $order->documentVersions()->whereKey($id)->first() : null;

        return $version ?? throw new RuntimeException('Choose a document version of this order.');
    }

    private static function versionSelect(Order $order): Select
    {
        return Select::make('version_id')
            ->label('Version')
            ->options(OrderInsights::versionOptions(OrderInsights::versions($order)))
            ->default(OrderInsights::versions($order)->first()?->id)
            ->required()
            ->native(false);
    }

    /** @return array<int, string> */
    private static function editableVersions(Order $order): array
    {
        return OrderInsights::versionOptions(OrderInsights::versions($order)->filter(fn (DocumentVersion $v) => filled($v->content)));
    }

    /** @return array<string, mixed> */
    private static function textFormData(?DocumentVersion $version): array
    {
        $content = (array) ($version?->content ?? []);

        return [
            'version_id' => $version?->id,
            'title' => $content['title'] ?? $version?->title,
            'subtitle' => $content['subtitle'] ?? null,
            'applicant_name' => $content['applicant_name'] ?? null,
            'blocks' => array_values(array_map(
                fn ($block) => ['type' => $block['type'] ?? 'paragraph', 'text' => $block['text'] ?? ''],
                (array) ($content['blocks'] ?? []),
            )),
        ];
    }

    private static function deliverToggle(): Toggle
    {
        return Toggle::make('deliver')
            ->label('Deliver the new version to the customer')
            ->helperText('Sent only if the new version passes file validation.')
            ->default(false);
    }

    private static function docxUpload(string $name, string $label): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->acceptedFileTypes(self::DOCX_TYPES)
            ->rule('extensions:docx')
            ->maxSize(self::MAX_UPLOAD_KB)
            ->storeFiles(false);
    }

    /** The uploaded file from a FileUpload field (temporary upload objects only; paths are never trusted). */
    private static function uploaded(mixed $state, string $extension): ?UploadedFile
    {
        $file = is_array($state) ? collect($state)->first(fn ($item) => $item instanceof UploadedFile) : $state;

        if (! $file instanceof UploadedFile) {
            return null;
        }

        if (strtolower($file->getClientOriginalExtension()) !== $extension) {
            throw new RuntimeException('Please upload a .'.$extension.' file.');
        }

        return $file;
    }
}
