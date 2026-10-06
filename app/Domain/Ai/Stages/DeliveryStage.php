<?php

namespace App\Domain\Ai\Stages;

use App\Domain\Ai\Pipeline\StageContext;
use App\Domain\Ai\Pipeline\StageFailure;
use App\Domain\Ai\Pipeline\StageResult;
use App\Domain\Delivery\DocumentDelivery;

/**
 * Hands the validated version to DocumentDelivery, which emails it (PDF +
 * DOCX). The AI job is complete once the delivery email is queued; the order
 * becomes DELIVERED (or a revision completed) when the provider accepts it.
 */
class DeliveryStage implements Stage
{
    public function __construct(private readonly DocumentDelivery $delivery) {}

    public function run(StageContext $ctx): StageResult
    {
        $version = RenderingStage::version($ctx)->fresh();

        if (! $version->isDeliverable()) {
            throw StageFailure::permanent('not_deliverable', 'The document version has no validated files to deliver.');
        }

        $email = $this->delivery->deliver($ctx->order->refresh(), $version, $ctx->revision());

        return StageResult::finished([
            'document_version_id' => $version->id,
            'email_message_id' => $email->id,
        ]);
    }
}
