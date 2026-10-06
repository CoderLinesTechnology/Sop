<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\EmailTemplateKey;
use App\Enums\PaymentRecordStatus;
use App\Filament\Resources\Orders\Actions\OrderManagementActions;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\AdminNames;
use App\Filament\Support\Operations\Format;
use App\Models\AuditLog;
use App\Models\DocumentVersion;
use App\Models\EmailMessage;
use App\Models\InformationRequest;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Revision;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * What happened around the order: document versions, emails, payments and
 * refunds, revisions, information requests, internal notes and the audit
 * trail.
 */
class OrderActivityTabs
{
    /** Audit entries shown on the order page (the full log is under System → Audit log). */
    private const AUDIT_LIMIT = 200;

    /** @return list<Tab> */
    public static function make(): array
    {
        return [
            self::documents(),
            self::emails(),
            self::payments(),
            self::revisions(),
            self::informationRequests(),
            self::notes(),
            self::audit(),
        ];
    }

    private static function documents(): Tab
    {
        return Tab::make('Documents')
            ->key('documents')
            ->icon(Heroicon::OutlinedDocumentText)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'documentVersions'))
            ->schema([
                RepeatableEntry::make('documentVersions')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Version'),
                        TableColumn::make('Source'),
                        TableColumn::make('QA'),
                        TableColumn::make('Length'),
                        TableColumn::make('Quality'),
                        TableColumn::make('Rendered'),
                        TableColumn::make('Files'),
                    ])
                    ->schema([
                        TextEntry::make('version_number')
                            ->formatStateUsing(fn ($state): string => 'v'.$state)
                            ->weight(FontWeight::SemiBold)
                            ->helperText(fn (DocumentVersion $record): string => str((string) $record->status)->ucfirst()
                                .($record->document?->current_version_id === $record->id ? ' · current' : '')
                                .($record->revision_id ? ' · revision' : '')),
                        TextEntry::make('source')
                            ->formatStateUsing(fn (?string $state): string => str((string) $state)->replace('_', ' ')->ucfirst()->toString())
                            ->helperText(fn (DocumentVersion $record): ?string => $record->created_by_admin_id
                                ? 'By '.AdminNames::name($record->created_by_admin_id)
                                : null),
                        TextEntry::make('qa_status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'passed' => 'success',
                                'failed' => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (?string $state): string => $state ? str($state)->ucfirst()->toString() : 'Not checked')
                            ->tooltip(fn (DocumentVersion $record): ?string => $record->qa_results
                                ? str((string) json_encode($record->qa_results, JSON_UNESCAPED_SLASHES))->limit(600)->toString()
                                : null)
                            ->placeholder('Not checked'),
                        TextEntry::make('length')
                            ->state(fn (DocumentVersion $record): string => number_format((int) $record->word_count).' words')
                            ->helperText(fn (DocumentVersion $record): string => number_format((int) $record->char_count).' characters'
                                .($record->page_count ? ' · '.$record->page_count.' '.str('page')->plural($record->page_count) : '')),
                        TextEntry::make('quality_score')
                            ->formatStateUsing(fn ($state): string => number_format((float) $state, 2))
                            ->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('rendered_at')
                            ->dateTime(Format::DATETIME)
                            ->helperText(fn (DocumentVersion $record): ?string => $record->template?->name)
                            ->placeholder('Not rendered'),
                        TextEntry::make('files')
                            ->state(fn (DocumentVersion $record): array => array_values(array_filter([
                                filled($record->pdf_path) ? 'View PDF' : null,
                                filled($record->pdf_path) ? 'PDF' : null,
                                filled($record->docx_path) ? 'DOCX' : null,
                            ])))
                            ->badge()
                            ->color('primary')
                            ->icon(fn (string $state): Heroicon => $state === 'View PDF' ? Heroicon::OutlinedEye : Heroicon::OutlinedArrowDownTray)
                            ->url(fn (string $state, DocumentVersion $record, $livewire): string => route('admin.support.orders.documents.show', array_filter([
                                'order' => $livewire->getRecord()->public_id,
                                'documentVersion' => $record->uuid,
                                'format' => $state === 'DOCX' ? 'docx' : 'pdf',
                                'inline' => $state === 'View PDF' ? 1 : null,
                            ])))
                            ->openUrlInNewTab()
                            ->placeholder('No files')
                            ->visible(fn ($livewire): bool => AdminContext::allows('viewDocuments', $livewire->getRecord())),
                    ])
                    ->visible(fn (Order $record): bool => $record->documentVersions->isNotEmpty()),
                EmptyState::make('No document versions yet')
                    ->description('Versions are created by the AI pipeline, by revisions and by administrator uploads or edits.')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn (Order $record): bool => $record->documentVersions->isEmpty()),
            ]);
    }

    private static function emails(): Tab
    {
        return Tab::make('Emails')
            ->key('emails')
            ->icon(Heroicon::OutlinedEnvelope)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'emails'))
            ->schema([
                RepeatableEntry::make('emails')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('Queued'),
                        TableColumn::make('Email'),
                        TableColumn::make('Status'),
                        TableColumn::make('Attempts'),
                        TableColumn::make('Last error'),
                        TableColumn::make('Content'),
                    ])
                    ->schema([
                        TextEntry::make('created_at')->dateTime(Format::DATETIME),
                        TextEntry::make('subject')
                            ->helperText(fn (EmailMessage $record): string => (EmailTemplateKey::tryFrom((string) $record->template_key)?->getLabel() ?? 'Email').' · to '.$record->to_email
                                .(data_get($record->meta, 'resend_of') ? ' · resend' : '')),
                        TextEntry::make('status')
                            ->badge()
                            ->helperText(fn (EmailMessage $record): ?string => match (true) {
                                $record->delivered_at !== null => 'Delivered '.Format::dateTime($record->delivered_at),
                                $record->sent_at !== null => 'Sent '.Format::dateTime($record->sent_at),
                                $record->failed_at !== null => 'Failed '.Format::dateTime($record->failed_at),
                                default => null,
                            }),
                        TextEntry::make('attempts'),
                        TextEntry::make('last_error')
                            ->formatStateUsing(fn (?string $state): ?string => $state ? str($state)->limit(140)->toString() : null)
                            ->tooltip(fn (EmailMessage $record): ?string => $record->last_error)
                            ->color('danger')
                            ->size(TextSize::Small)
                            ->placeholder(Format::PLACEHOLDER),
                        TextEntry::make('preview')
                            ->state('Preview')
                            ->icon(Heroicon::OutlinedEye)
                            ->color('primary')
                            ->url(fn (EmailMessage $record): string => route('admin.support.emails.preview', ['email' => $record->uuid]))
                            ->openUrlInNewTab()
                            ->visible(fn ($livewire): bool => AdminContext::allows('viewEmails', $livewire->getRecord())),
                    ])
                    ->visible(fn (Order $record): bool => $record->emails->isNotEmpty()),
                EmptyState::make('No emails sent')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn (Order $record): bool => $record->emails->isEmpty()),
            ]);
    }

    private static function payments(): Tab
    {
        return Tab::make('Payments & refunds')
            ->key('payments')
            ->icon(Heroicon::OutlinedCreditCard)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'payments'))
            ->schema([
                Section::make('Payments')
                    ->schema([
                        TextEntry::make('refundable')
                            ->label('Refundable balance')
                            ->state(function (Order $record): string {
                                $payment = self::capturedPayment($record);

                                return $payment ? Format::money($payment->refundableAmount(), $payment->currency) : Format::PLACEHOLDER;
                            })
                            ->weight(FontWeight::SemiBold),
                        RepeatableEntry::make('payments')
                            ->hiddenLabel()
                            ->state(fn (Order $record) => $record->payments->sortByDesc('id')->values())
                            ->table([
                                TableColumn::make('Reference'),
                                TableColumn::make('Purpose'),
                                TableColumn::make('Amount'),
                                TableColumn::make('Status'),
                                TableColumn::make('Paid'),
                                TableColumn::make('Notes'),
                            ])
                            ->schema([
                                TextEntry::make('reference')
                                    ->fontFamily(FontFamily::Mono)
                                    ->size(TextSize::Small)
                                    ->copyable()
                                    ->url(fn (Payment $record): ?string => class_exists(PaymentResource::class) && PaymentResource::canView($record)
                                        ? PaymentResource::getUrl('view', ['record' => $record])
                                        : null)
                                    ->helperText(fn (Payment $record): string => ucfirst((string) $record->provider).($record->channel ? ' · '.$record->channel : '')),
                                TextEntry::make('purpose')->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                                TextEntry::make('amount')
                                    ->formatStateUsing(fn ($state, Payment $record): string => Format::money((int) $state, $record->currency))
                                    ->helperText(fn (Payment $record): ?string => $record->fees ? 'Fees '.Format::money($record->fees, $record->currency) : null),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('paid_at')
                                    ->dateTime(Format::DATETIME)
                                    ->helperText(fn (Payment $record): ?string => $record->verified_at ? 'Verified '.Format::dateTime($record->verified_at) : null)
                                    ->placeholder(Format::PLACEHOLDER),
                                TextEntry::make('notes')
                                    ->state(fn (Payment $record): ?string => $record->failure_reason
                                        ?: ($record->mismatch_details ? 'Mismatch: '.str((string) json_encode($record->mismatch_details))->limit(160) : null)
                                        ?: $record->gateway_response)
                                    ->color(fn (Payment $record): ?string => $record->status === PaymentRecordStatus::Mismatch || $record->failure_reason ? 'danger' : null)
                                    ->size(TextSize::Small)
                                    ->placeholder(Format::PLACEHOLDER),
                            ])
                            ->visible(fn (Order $record): bool => $record->payments->isNotEmpty()),
                        EmptyState::make('No payment attempts')
                            ->icon(Heroicon::OutlinedCreditCard)
                            ->contained(false)
                            ->visible(fn (Order $record): bool => $record->payments->isEmpty()),
                    ]),
                Section::make('Refunds')
                    ->schema([
                        RepeatableEntry::make('refunds')
                            ->hiddenLabel()
                            ->state(fn (Order $record) => $record->refunds->sortByDesc('id')->values())
                            ->table([
                                TableColumn::make('Requested'),
                                TableColumn::make('Amount'),
                                TableColumn::make('Status'),
                                TableColumn::make('Reason'),
                                TableColumn::make('Requested by'),
                                TableColumn::make('Approved by'),
                                TableColumn::make('Processed'),
                            ])
                            ->schema([
                                TextEntry::make('created_at')->dateTime(Format::DATETIME),
                                TextEntry::make('amount')->formatStateUsing(fn ($state, Refund $record): string => Format::money((int) $state, $record->currency)),
                                TextEntry::make('status')
                                    ->badge()
                                    ->helperText(fn (Refund $record): ?string => $record->failure_reason ?: ($record->provider_status ? 'Provider: '.$record->provider_status : null)),
                                TextEntry::make('reason')
                                    ->formatStateUsing(fn (?string $state): string => str((string) $state)->limit(160)->toString())
                                    ->tooltip(fn (Refund $record): ?string => $record->notes ? 'Notes: '.$record->notes : null),
                                TextEntry::make('requested')
                                    ->state(fn (Refund $record): string => $record->requestedBy?->name ?? ucfirst((string) $record->requested_by)),
                                TextEntry::make('approvedBy.name')->placeholder(Format::PLACEHOLDER),
                                TextEntry::make('processed_at')
                                    ->dateTime(Format::DATETIME)
                                    ->helperText(fn (Refund $record): ?string => $record->provider_refund_id ? 'Ref '.$record->provider_refund_id : null)
                                    ->placeholder(Format::PLACEHOLDER),
                            ])
                            ->visible(fn (Order $record): bool => $record->refunds->isNotEmpty()),
                        EmptyState::make('No refunds')
                            ->icon(Heroicon::OutlinedReceiptRefund)
                            ->contained(false)
                            ->visible(fn (Order $record): bool => $record->refunds->isEmpty()),
                    ]),
                Section::make('Coupon redemption')
                    ->columns(['default' => 2, 'md' => 4])
                    ->schema([
                        TextEntry::make('couponRedemption.coupon.code')->label('Coupon')->fontFamily(FontFamily::Mono),
                        TextEntry::make('couponRedemption.status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => ucfirst((string) ($state->value ?? $state))),
                        TextEntry::make('couponRedemption.discount_amount')
                            ->label('Discount')
                            ->formatStateUsing(fn ($state, Order $record): string => Format::money((int) $state, $record->couponRedemption?->currency)),
                        TextEntry::make('couponRedemption.redeemed_at')->label('Redeemed')->dateTime(Format::DATETIME)->placeholder('Not redeemed'),
                    ])
                    ->visible(fn (Order $record): bool => $record->couponRedemption !== null),
            ]);
    }

    private static function revisions(): Tab
    {
        return Tab::make('Revisions')
            ->key('revisions')
            ->icon(Heroicon::OutlinedArrowPath)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'revisions'))
            ->schema([
                RepeatableEntry::make('revisions')
                    ->hiddenLabel()
                    ->table([
                        TableColumn::make('#')->width('3rem'),
                        TableColumn::make('Requested'),
                        TableColumn::make('Status'),
                        TableColumn::make('Request'),
                        TableColumn::make('Fee'),
                        TableColumn::make('Completed'),
                    ])
                    ->schema([
                        TextEntry::make('number'),
                        TextEntry::make('requested_at')
                            ->dateTime(Format::DATETIME)
                            ->helperText(fn (Revision $record): string => strtoupper((string) $record->mode).' revision'),
                        TextEntry::make('status')
                            ->badge()
                            ->helperText(fn (Revision $record): ?string => $record->rejected_reason),
                        TextEntry::make('request_text')
                            ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(nl2br(e(str((string) $state)->limit(600)->toString()))))
                            ->helperText(fn (Revision $record): ?string => $record->admin_note ? 'Admin note: '.$record->admin_note : null),
                        TextEntry::make('fee_amount')
                            ->formatStateUsing(fn ($state, Revision $record): string => (int) $state > 0 ? Format::money((int) $state, $record->currency) : 'Included'),
                        TextEntry::make('completed_at')
                            ->dateTime(Format::DATETIME)
                            ->helperText(fn (Revision $record): ?string => $record->started_at ? 'Started '.Format::dateTime($record->started_at) : null)
                            ->placeholder(Format::PLACEHOLDER),
                    ])
                    ->visible(fn (Order $record): bool => $record->revisions->isNotEmpty()),
                EmptyState::make('No revisions requested')
                    ->description(fn (Order $record): string => $record->revisionsRemaining().' of '.(int) $record->revisions_allowed.' included revisions remaining.')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Order $record): bool => $record->revisions->isEmpty()),
            ]);
    }

    private static function informationRequests(): Tab
    {
        return Tab::make('Information requests')
            ->key('information')
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'informationRequests'))
            ->schema([
                RepeatableEntry::make('informationRequests')
                    ->hiddenLabel()
                    ->schema([
                        Grid::make(['default' => 2, 'md' => 5])->schema([
                            TextEntry::make('requested_at')->label('Requested')->dateTime(Format::DATETIME),
                            TextEntry::make('status')
                                ->label('Status')
                                ->badge()
                                ->color(fn (?string $state): string => match ($state) {
                                    'open' => 'warning',
                                    'answered' => 'success',
                                    'expired' => 'danger',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state)),
                            TextEntry::make('source')
                                ->label('Asked by')
                                ->state(fn (InformationRequest $record): string => $record->source === 'admin'
                                    ? 'Administrator'.($record->requested_by_admin_id ? ' · '.AdminNames::name($record->requested_by_admin_id) : '')
                                    : 'AI pipeline'),
                            TextEntry::make('expires_at')->label('Expires')->dateTime(Format::DATETIME)->placeholder(Format::PLACEHOLDER),
                            TextEntry::make('answered_at')
                                ->label('Answered')
                                ->dateTime(Format::DATETIME)
                                ->helperText(fn (InformationRequest $record): ?string => $record->reminder_sent_at ? 'Reminder sent '.Format::dateTime($record->reminder_sent_at) : null)
                                ->placeholder('Not answered'),
                        ]),
                        TextEntry::make('questions')
                            ->label('Questions and answers')
                            ->state(fn (InformationRequest $record): HtmlString => self::questionsAndAnswers($record)),
                    ])
                    ->visible(fn (Order $record): bool => $record->informationRequests->isNotEmpty()),
                EmptyState::make('No information requests')
                    ->description('When essential details are missing, the AI pipeline (or an administrator) asks the customer up to five short questions.')
                    ->icon(Heroicon::OutlinedQuestionMarkCircle)
                    ->visible(fn (Order $record): bool => $record->informationRequests->isEmpty()),
            ]);
    }

    private static function questionsAndAnswers(InformationRequest $request): HtmlString
    {
        $answers = (array) $request->answers;
        $items = [];

        foreach ((array) $request->questions as $question) {
            $key = (string) ($question['key'] ?? '');
            $answer = $answers[$key] ?? null;

            $items[] = '<li style="margin-bottom:.5rem"><div style="font-weight:600">'.e((string) ($question['question'] ?? '')).'</div>'
                .(filled($question['why'] ?? null) ? '<div style="font-size:12px;opacity:.7">Why: '.e((string) $question['why']).'</div>' : '')
                .'<div>'.($answer !== null ? nl2br(e((string) $answer)) : '<span style="opacity:.6">No answer</span>').'</div></li>';
        }

        return new HtmlString('<ol style="list-style:decimal;padding-inline-start:1.25rem;margin:0">'.implode('', $items).'</ol>');
    }

    private static function notes(): Tab
    {
        return Tab::make('Notes')
            ->key('notes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->badge(fn (Order $record): ?int => OrderTabCounts::get($record, 'notes'))
            ->schema([
                Section::make('Internal notes')
                    ->description('Visible to administrators only — never shown to the customer.')
                    ->headerActions([
                        OrderManagementActions::addNote()->name('addNoteFromNotesTab'),
                    ])
                    ->schema([
                        RepeatableEntry::make('notes')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('body')
                                    ->hiddenLabel()
                                    ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(nl2br(e((string) $state))))
                                    ->helperText(fn (OrderNote $record): string => ($record->admin?->name ?? 'Administrator').' · '.Format::dateTime($record->created_at)),
                            ])
                            ->visible(fn (Order $record): bool => $record->notes->isNotEmpty()),
                        EmptyState::make('No notes yet')
                            ->description('Add context for your team: what you checked, what you told the customer, what to do next.')
                            ->icon(Heroicon::OutlinedPencilSquare)
                            ->contained(false)
                            ->visible(fn (Order $record): bool => $record->notes->isEmpty()),
                    ]),
            ]);
    }

    private static function audit(): Tab
    {
        return Tab::make('Audit trail')
            ->key('audit')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->schema(Schema::make()->deferLoading()->components([
                RepeatableEntry::make('audit_trail')
                    ->hiddenLabel()
                    ->state(fn (Order $record): Collection => self::auditTrail($record))
                    ->table([
                        TableColumn::make('When')->width('11rem'),
                        TableColumn::make('Action'),
                        TableColumn::make('By'),
                        TableColumn::make('Details'),
                    ])
                    ->schema([
                        TextEntry::make('created_at')->dateTime(Format::DATETIME),
                        TextEntry::make('action')
                            ->fontFamily(FontFamily::Mono)
                            ->size(TextSize::Small),
                        TextEntry::make('actor')
                            ->state(fn (AuditLog $record): string => $record->admin?->name ?? $record->actor_label ?? ucfirst((string) $record->actor_type))
                            ->helperText(fn (AuditLog $record): ?string => $record->ip_address),
                        TextEntry::make('details')
                            ->state(fn (AuditLog $record): HtmlString => self::auditDetails($record)),
                    ])
                    ->visible(fn (Order $record): bool => self::auditTrail($record)->isNotEmpty()),
                EmptyState::make('No audit entries')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (Order $record): bool => self::auditTrail($record)->isEmpty()),
            ]));
    }

    /** @return Collection<int, AuditLog> loaded once per request and kept on the record */
    private static function auditTrail(Order $order): Collection
    {
        if (! $order->relationLoaded('operationsAuditTrail')) {
            $order->setRelation('operationsAuditTrail', AuditLog::query()
                ->with('admin:id,name')
                ->where('target_type', class_basename($order))
                ->where('target_id', (string) $order->getKey())
                ->latest('id')
                ->limit(self::AUDIT_LIMIT)
                ->get());
        }

        return $order->getRelation('operationsAuditTrail');
    }

    private static function auditDetails(AuditLog $log): HtmlString
    {
        $parts = [];
        foreach (['before' => 'Before', 'after' => 'After', 'meta' => 'Details'] as $attribute => $label) {
            $value = $log->{$attribute};
            if (blank($value)) {
                continue;
            }

            $summary = collect((array) $value)
                ->map(fn ($item, $key) => e(Format::humanKey($key)).': '.e(str(is_scalar($item) || $item === null ? var_export($item, true) : (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->replace("'", '')->limit(200)->toString()))
                ->implode('; ');

            $parts[] = '<div><span style="font-weight:600">'.$label.':</span> '.$summary.'</div>';
        }

        return new HtmlString($parts ? '<div style="font-size:12px;line-height:1.5">'.implode('', $parts).'</div>' : '<span style="opacity:.6">'.Format::PLACEHOLDER.'</span>');
    }

    private static function capturedPayment(Order $order): ?Payment
    {
        return $order->payments
            ->where('purpose', 'order')
            ->filter(fn (Payment $payment) => in_array($payment->status, [PaymentRecordStatus::Success, PaymentRecordStatus::PartiallyRefunded], true))
            ->sortByDesc('id')
            ->first();
    }
}
