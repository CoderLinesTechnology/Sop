<?php

namespace App\Domain\Documents;

use App\Enums\DocumentKind;
use App\Models\AdminUser;
use App\Models\AiJob;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Order;
use App\Models\Revision;
use Illuminate\Support\Facades\DB;

/** Creates (unrendered) document versions from an approved DocumentModel. (Owned by the document engine.) */
class DocumentFactory
{
    /**
     * Finds or creates the order's Document and adds the next version: the
     * approved content, its counts, the formatting snapshot (template plus
     * requirement overrides) and the requirements it was written against.
     * Presentation details the model leaves open are completed here: the
     * resolved language variant, the applicant's name from the order and,
     * for letters, today's date in the destination's format.
     */
    public function createVersion(
        Order $order,
        DocumentModel $model,
        DocumentTemplate $template,
        ResolvedRequirements $requirements,
        string $source = 'ai',
        ?AiJob $job = null,
        ?Revision $revision = null,
        ?AdminUser $admin = null,
        ?float $qualityScore = null,
    ): DocumentVersion {
        $kind = $order->documentKind();
        $model = $this->complete($model, $order, $requirements, $template, $kind);
        $snapshot = TemplateSnapshot::make($template, $requirements, [
            'document_kind' => $kind,
            'document_type' => self::documentType($order),
            'institution' => $order->institution,
            'programme' => $order->programme,
        ]);

        return DB::transaction(function () use ($order, $model, $template, $requirements, $source, $job, $revision, $admin, $qualityScore, $kind, $snapshot) {
            // Serialise version numbering per order.
            Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $document = Document::query()->where('order_id', $order->id)->latest('id')->first()
                ?? Document::query()->create([
                    'order_id' => $order->id,
                    'kind' => $kind,
                    'title' => mb_substr($model->title ?: self::documentType($order), 0, 255),
                    'status' => 'in_progress',
                ]);

            return DocumentVersion::query()->create([
                'document_id' => $document->id,
                'order_id' => $order->id,
                'revision_id' => $revision?->id,
                'ai_job_id' => $job?->id,
                'version_number' => $document->nextVersionNumber(),
                'source' => mb_substr($source, 0, 20),
                'status' => 'draft',
                'title' => mb_substr($model->title, 0, 255),
                'content' => $model->toArray(),
                'plain_text' => $model->fullText(),
                'word_count' => $model->wordCount(),
                'char_count' => $model->characterCount(true),
                'char_count_no_spaces' => $model->characterCount(false),
                'language_variant' => $model->languageVariant,
                'document_template_id' => $template->exists ? $template->getKey() : null,
                'template_snapshot' => $snapshot,
                'requirements_snapshot' => $requirements->toArray(),
                'quality_score' => $qualityScore === null ? null : max(0, min(99.99, round($qualityScore, 2))), // decimal(4,2)
                'created_by_admin_id' => $admin?->id,
            ]);
        });
    }

    /** The human document type used in titles and file names, e.g. "Statement of Purpose". */
    public static function documentType(Order $order): string
    {
        $kind = DocumentKind::tryFrom($order->documentKind());

        return $kind && $kind !== DocumentKind::Custom ? $kind->getLabel() : $order->serviceName();
    }

    private function complete(DocumentModel $model, Order $order, ResolvedRequirements $requirements, DocumentTemplate $template, string $kind): DocumentModel
    {
        $date = $model->date;
        if (blank($date) && DocumentLayout::isLetter($model, $kind)) {
            $date = now()->format($template->date_format ?: $requirements->dateFormat ?: LanguageVariant::dateFormat($requirements->languageVariant));
        }

        return new DocumentModel(
            title: $model->title,
            subtitle: $model->subtitle,
            applicantName: filled($model->applicantName) ? $model->applicantName : ($order->applicant_name ?: $order->customer_name),
            blocks: $model->blocks,
            languageVariant: LanguageVariant::normalize($requirements->languageVariant) ?? LanguageVariant::DEFAULT,
            date: $date,
        );
    }
}
