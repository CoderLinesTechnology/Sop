<?php

namespace App\Domain\Orders;

use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\StepStatus;
use App\Models\AiJob;
use App\Models\Order;

/**
 * Customer-facing progress, derived from the real pipeline checkpoints so the
 * page never claims a stage has finished before it has.
 */
final class OrderProgress
{
    /** @var list<array{key:string,label:string,stages:list<PipelineStage>}> */
    private const STEPS = [
        ['key' => 'received', 'label' => 'Information received', 'stages' => []],
        ['key' => 'background', 'label' => 'Background analyzed', 'stages' => [PipelineStage::Ingestion, PipelineStage::Analysis]],
        ['key' => 'research', 'label' => 'Programme researched', 'stages' => [PipelineStage::Research]],
        ['key' => 'verified', 'label' => 'Information verified', 'stages' => [PipelineStage::Verification, PipelineStage::Requirements]],
        ['key' => 'written', 'label' => 'Document written', 'stages' => [PipelineStage::Strategy, PipelineStage::Writing, PipelineStage::Editorial]],
        ['key' => 'review', 'label' => 'Final review', 'stages' => [PipelineStage::FactCheck, PipelineStage::QualityReview, PipelineStage::Limits]],
        ['key' => 'files', 'label' => 'Preparing your files', 'stages' => [PipelineStage::Formatting, PipelineStage::Rendering, PipelineStage::FileQa]],
        ['key' => 'email', 'label' => 'Email delivery', 'stages' => [PipelineStage::Delivery]],
    ];

    /** @return list<array{key:string,label:string,state:string}> state: done|current|pending */
    public function steps(Order $order): array
    {
        $paid = $order->isPaid() || in_array($order->status, [OrderStatus::Delivered, OrderStatus::PartiallyRefunded], true);
        $delivered = $order->status === OrderStatus::Delivered || $order->delivered_at !== null;

        /** @var AiJob|null $job */
        $job = $order->aiJobs()->where('kind', AiJob::KIND_ORDER)->latest('id')->first();
        $finished = $job
            ? $job->steps()->whereIn('status', [StepStatus::Completed->value, StepStatus::Skipped->value])->pluck('stage')
                ->map(fn ($stage) => $stage instanceof PipelineStage ? $stage->value : (string) $stage)->unique()->values()->all()
            : [];

        $steps = [];
        $currentAssigned = false;

        foreach (self::STEPS as $definition) {
            $done = match ($definition['key']) {
                'received' => $paid,
                'email' => $delivered,
                default => $delivered || ($definition['stages'] !== []
                    && array_diff(array_map(fn (PipelineStage $stage) => $stage->value, $definition['stages']), $finished) === []),
            };

            $state = 'pending';
            if ($done) {
                $state = 'done';
            } elseif (! $currentAssigned && $paid) {
                $state = 'current';
                $currentAssigned = true;
            }

            $steps[] = ['key' => $definition['key'], 'label' => $definition['label'], 'state' => $state];
        }

        return $steps;
    }
}
