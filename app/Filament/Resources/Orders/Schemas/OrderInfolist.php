<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Filament\Resources\Orders\OrderInsights;
use App\Filament\Support\Operations\Format;
use App\Filament\Support\Operations\OrderStatusGroups;
use App\Models\Order;
use App\Models\Refund;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

/**
 * The order detail page: status call-outs above a tab set covering the
 * customer and application, answers, uploads, applicant profile, research
 * dossier, requirement log, AI processing history, documents, emails,
 * payments and refunds, revisions, information requests, internal notes and
 * the order's audit trail.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                ...self::callouts(),
                Tabs::make('Order')
                    ->key('orderTabs')
                    ->persistTabInQueryString('tab')
                    ->tabs([
                        OrderOverviewTab::make(),
                        ...OrderCustomerTabs::make(),
                        ...OrderPipelineTabs::make(),
                        ...OrderActivityTabs::make(),
                    ]),
            ]);
    }

    /** @return list<Callout> */
    private static function callouts(): array
    {
        return [
            Callout::make('Processing is paused')
                ->description(fn (Order $record): string => 'Paused '.$record->paused_at?->diffForHumans()
                    .($record->pause_reason ? ' — '.$record->pause_reason : '.')
                    .' Resume processing from the Processing menu when ready.')
                ->warning()
                ->visible(fn (Order $record): bool => $record->isPaused()),

            Callout::make(fn (Order $record): string => $record->status->getLabel())
                ->description(fn (Order $record): string => self::attentionDescription($record))
                ->danger()
                ->visible(fn (Order $record): bool => in_array($record->status, OrderStatusGroups::FAILED, true)),

            Callout::make('Waiting for the customer')
                ->description(function (Order $record): string {
                    $request = $record->openInformationRequest;
                    if (! $request) {
                        return 'The order is waiting for more information from the customer.';
                    }

                    $count = count($request->questions ?? []);

                    return "Asked {$count} ".str('question')->plural($count).' '.$request->requested_at?->diffForHumans()
                        .($request->expires_at ? '; the request expires '.$request->expires_at->diffForHumans().'.' : '.');
                })
                ->info()
                ->visible(fn (Order $record): bool => $record->status === OrderStatus::NeedsInformation),

            Callout::make('Refund in progress')
                ->description(function (Order $record): string {
                    /** @var Refund|null $refund */
                    $refund = $record->refunds->first(fn (Refund $refund) => in_array($refund->status, [RefundStatus::Requested, RefundStatus::Approved, RefundStatus::Processing], true));

                    return $refund
                        ? Format::money($refund->amount, $refund->currency).' — '.$refund->status->getLabel().'. See Payments & refunds.'
                        : '';
                })
                ->warning()
                ->visible(fn (Order $record): bool => $record->refunds->contains(
                    fn (Refund $refund) => in_array($refund->status, [RefundStatus::Requested, RefundStatus::Approved, RefundStatus::Processing], true),
                )),
        ];
    }

    private static function attentionDescription(Order $record): string
    {
        $job = OrderInsights::latestJob($record);

        return match ($record->status) {
            OrderStatus::ProcessingFailed => 'The AI pipeline stopped'
                .($job?->current_stage ? ' at "'.$job->current_stage->getLabel().'"' : '')
                .($job?->last_error_message ? ': '.str($job->last_error_message)->limit(220) : '.')
                .' Retry, skip the failed step or regenerate from the Processing menu.',
            OrderStatus::DeliveryFailed => 'The document email could not be delivered to '.$record->email.'. Check the Emails tab, then resend the document email.',
            OrderStatus::ManualReview => 'This order was handed to a person for review'
                .($job?->last_error_message ? ': '.str($job->last_error_message)->limit(220) : '.')
                .' Review the documents and AI history, then deliver, retry or change the status.',
            default => '',
        };
    }
}
