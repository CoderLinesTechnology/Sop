<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Stages of the document-production pipeline, in execution order. A workflow
 * may disable optional stages; required stages always run.
 */
enum PipelineStage: string implements HasLabel
{
    case Ingestion = 'ingestion';
    case Analysis = 'analysis';
    case Research = 'research';
    case Verification = 'verification';
    case Requirements = 'requirements';
    case Strategy = 'strategy';
    case Writing = 'writing';
    case Editorial = 'editorial';
    case FactCheck = 'fact_check';
    case QualityReview = 'quality_review';
    case Limits = 'limits';
    case Formatting = 'formatting';
    case Rendering = 'rendering';
    case FileQa = 'file_qa';
    case Delivery = 'delivery';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ingestion => 'Document ingestion',
            self::Analysis => 'Application analysis',
            self::Research => 'Deep research',
            self::Verification => 'Research verification',
            self::Requirements => 'Requirement resolution',
            self::Strategy => 'Narrative strategy',
            self::Writing => 'Writing',
            self::Editorial => 'Editorial pass',
            self::FactCheck => 'Factual review',
            self::QualityReview => 'Quality & prompt review',
            self::Limits => 'Length & requirement check',
            self::Formatting => 'Document formatting',
            self::Rendering => 'PDF & DOCX generation',
            self::FileQa => 'File validation',
            self::Delivery => 'Email delivery',
        };
    }

    /** The order status that represents this stage to the outside world. */
    public function orderStatus(): OrderStatus
    {
        return match ($this) {
            self::Ingestion, self::Analysis, self::Research, self::Verification, self::Requirements => OrderStatus::Researching,
            self::Strategy, self::Writing, self::Editorial => OrderStatus::Writing,
            self::FactCheck, self::QualityReview => OrderStatus::QualityReview,
            self::Limits, self::Formatting, self::Rendering, self::FileQa => OrderStatus::FinalReview,
            self::Delivery => OrderStatus::DeliveryPending,
        };
    }

    /** Stages that cannot be disabled by a workflow. */
    public function isRequired(): bool
    {
        return in_array($this, [
            self::Ingestion, self::Analysis, self::Requirements, self::Writing, self::FactCheck,
            self::QualityReview, self::Limits, self::Formatting, self::Rendering, self::FileQa, self::Delivery,
        ], true);
    }

    /** Stages that call a language model. */
    public function usesModel(): bool
    {
        return ! in_array($this, [self::Requirements, self::Formatting, self::Rendering, self::FileQa, self::Delivery], true);
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        return self::cases();
    }

    public function position(): int
    {
        return array_search($this, self::cases(), true);
    }
}
