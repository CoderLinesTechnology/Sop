<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Documents\DocumentFactory;
use App\Domain\Documents\DocumentModel;
use App\Models\DocumentTemplate;
use App\Models\QualityReview;

/**
 * Builds the final DocumentModel (title from the template or service,
 * subtitle "Programme — Institution", applicant name, letter parts) and
 * creates an unrendered DocumentVersion through the document engine, with
 * the passing quality score. The factory completes presentation details
 * (letter date in the destination's format, language variant).
 */
class FormattingStage implements Stage
{
    public function __construct(private readonly DocumentFactory $factory) {}

    public function run(StageContext $ctx): StageResult
    {
        $draft = $ctx->currentDraft() ?? throw StageFailure::permanent('missing_draft', 'There is no approved draft to format.');
        $template = $ctx->template() ?? throw StageFailure::permanent('missing_template', 'No document template is available.');
        $requirements = $ctx->requirements();
        $order = $ctx->order;

        $model = new DocumentModel(
            title: $this->title($ctx, $template),
            subtitle: collect([$order->programme, $order->institution])->filter()->implode(' — ') ?: null,
            applicantName: $ctx->applicantName(),
            blocks: $this->blocks($ctx, $draft->blocks),
            languageVariant: $ctx->languageVariant(),
        );

        $quality = QualityReview::query()
            ->where('ai_job_id', $ctx->job->id)
            ->where('passed', true)
            ->orderByDesc('round')
            ->value('overall_score');

        $version = $this->factory->createVersion(
            $order,
            $model,
            $template,
            $requirements,
            'ai',
            $ctx->job,
            $ctx->revision(),
            null,
            $quality !== null ? (float) $quality : null,
        );

        return StageResult::completed([
            'document_version_id' => $version->id,
            'document_version_uuid' => $version->uuid,
            'final_document' => $model->toArray(),
            'quality_score' => $quality !== null ? (float) $quality : null,
        ]);
    }

    private function title(StageContext $ctx, DocumentTemplate $template): string
    {
        $pattern = trim((string) $template->title_template);
        if ($pattern === '') {
            return $ctx->documentTypeLabel();
        }

        $values = [
            'document_type' => $ctx->documentTypeLabel(),
            'service' => $ctx->order->serviceName(),
            'programme' => (string) $ctx->order->programme,
            'institution' => (string) $ctx->order->institution,
            'applicant_name' => (string) $ctx->applicantName(),
            'degree_level' => (string) $ctx->order->degree_level,
        ];

        $title = preg_replace_callback('/\{\{?\s*([a-z_]+)\s*\}?\}/i', fn ($m) => $values[strtolower($m[1])] ?? '', $pattern) ?? $pattern;
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title, ' —-|:');

        return $title !== '' ? $title : $ctx->documentTypeLabel();
    }

    /**
     * Letters keep (or receive) salutation, closing and signature; essays
     * never carry letter parts.
     *
     * @param  list<array{type:string,text:string}>  $blocks
     * @return list<array{type:string,text:string}>
     */
    private function blocks(StageContext $ctx, array $blocks): array
    {
        if (! $ctx->isLetter()) {
            return array_values(array_filter($blocks, fn ($b) => in_array($b['type'], ['heading', 'paragraph'], true)));
        }

        $types = array_column($blocks, 'type');
        if (! in_array('salutation', $types, true)) {
            array_unshift($blocks, ['type' => 'salutation', 'text' => 'Dear Admissions Committee,']);
        }
        if (! in_array('closing', $types, true)) {
            $blocks[] = ['type' => 'closing', 'text' => $ctx->languageVariant() === 'en-US' ? 'Sincerely,' : 'Yours sincerely,'];
        }
        if (! in_array('signature', $types, true) && ($name = $ctx->applicantName())) {
            $blocks[] = ['type' => 'signature', 'text' => $name];
        }

        return $blocks;
    }
}
