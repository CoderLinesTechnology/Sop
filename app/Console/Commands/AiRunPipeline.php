<?php

namespace App\Console\Commands;

use App\Domain\Ai\Pipeline\PipelineWorker;
use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Orders\FulfillmentDenied;
use App\Models\AiJob;
use App\Models\AiJobStep;
use App\Models\Order;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Drive an order's AI pipeline inline from the terminal, through the same
 * lease-based worker the web runtime uses. With AI_PROVIDER=fake this runs
 * the whole pipeline locally without network access.
 */
#[Signature('ai:run-pipeline
    {order : Order reference (ST-XXXX-XXXX), public id or numeric id}
    {--start : Start the pipeline first if the order has none (the order must be paid)}
    {--wait : Wait for retry delays instead of fast-forwarding them}
    {--max-seconds=3600 : Give up after this many seconds}')]
#[Description('Run an order\'s AI pipeline inline (development and support tool)')]
class AiRunPipeline extends Command
{
    public function handle(PipelineWorker $worker, PipelineDispatcher $dispatcher): int
    {
        $key = (string) $this->argument('order');
        $order = Order::query()
            ->where('reference', strtoupper($key))
            ->orWhere('public_id', $key)
            ->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key))
            ->first();

        if (! $order) {
            $this->error("No order matches [{$key}].");

            return self::FAILURE;
        }

        $job = AiJob::query()->where('order_id', $order->id)->orderByDesc('id')->first();

        if (! $job) {
            if (! $this->option('start')) {
                $this->error('This order has no AI job yet. Re-run with --start to start the pipeline.');

                return self::FAILURE;
            }

            try {
                $job = $dispatcher->startForOrder($order);
            } catch (FulfillmentDenied $e) {
                $this->error('The pipeline cannot start: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info("Running job {$job->uuid} ({$job->kind}, provider {$job->provider}) for order {$order->reference}…");

        $job = $worker->runToCompletion($job, skipDelays: ! $this->option('wait'), maxSeconds: max(10, (int) $this->option('max-seconds')));

        $this->table(
            ['#', 'Stage', 'Attempt', 'Status', 'Model', 'Tokens in/out', 'Cost (USD)', 'ms', 'Error'],
            AiJobStep::query()->where('ai_job_id', $job->id)->orderBy('id')->get()->map(fn (AiJobStep $step) => [
                $step->sequence,
                $step->stage?->value,
                $step->attempt,
                $step->status?->value,
                $step->model,
                $step->input_tokens.' / '.$step->output_tokens,
                number_format((float) $step->cost_usd, 4),
                $step->duration_ms,
                $step->error_code,
            ])->all(),
        );

        $order->refresh();
        $this->line(sprintf(
            'Job: %s (stage %s) · Order: %s · LLM calls: %d · Searches: %d · Cost: $%.4f%s',
            $job->status->value,
            $job->current_stage?->value ?? '-',
            $order->status->value,
            $job->llm_calls,
            $job->search_calls,
            (float) $job->total_cost_usd,
            $job->last_error_code ? ' · Last error: '.$job->last_error_code.' — '.$job->last_error_message : '',
        ));

        return self::SUCCESS;
    }
}
