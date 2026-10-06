<?php

namespace App\Domain\Orders;

use App\Domain\Ai\PipelineDispatcher;
use App\Domain\Email\OrderEmailVariables;
use App\Domain\Email\TransactionalMailer;
use App\Enums\EmailTemplateKey;
use App\Enums\FieldSection;
use App\Enums\OrderStatus;
use App\Models\AdminUser;
use App\Models\InformationRequest;
use App\Models\Order;
use App\Models\OrderAnswer;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * When essential information is missing the system asks instead of
 * inventing it: the order moves to NEEDS_INFORMATION, the customer gets a
 * short email with a secure link, and their answer resumes the same order.
 */
class InformationRequestService
{
    public const MAX_QUESTIONS = 5;

    public function __construct(
        private readonly OrderStateMachine $states,
        private readonly TransactionalMailer $mailer,
    ) {}

    /**
     * @param  list<array{question:string, why?:string, key?:string}|string>  $questions
     */
    public function request(Order $order, array $questions, string $source = 'ai', ?AdminUser $admin = null): InformationRequest
    {
        $normalized = [];
        foreach (array_slice($questions, 0, self::MAX_QUESTIONS) as $i => $q) {
            $text = trim(is_array($q) ? (string) ($q['question'] ?? '') : (string) $q);
            if ($text === '') {
                continue;
            }
            $normalized[] = [
                'key' => 'q'.($i + 1),
                'question' => mb_substr($text, 0, 500),
                'why' => is_array($q) ? mb_substr(trim((string) ($q['why'] ?? '')), 0, 300) : '',
            ];
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('At least one question is required.');
        }

        $request = DB::transaction(function () use ($order, $normalized, $source, $admin) {
            $order->informationRequests()->where('status', 'open')->update(['status' => 'cancelled', 'updated_at' => now()]);

            $request = InformationRequest::query()->create([
                'order_id' => $order->id,
                'source' => $source,
                'questions' => $normalized,
                'status' => 'open',
                'requested_by_admin_id' => $admin?->id,
                'requested_at' => now(),
                'expires_at' => now()->addHours((int) Settings::get('orders.needs_info_timeout_hours', 72)),
            ]);

            if ($order->status !== OrderStatus::NeedsInformation) {
                $this->states->transition($order, OrderStatus::NeedsInformation, $admin ? 'admin' : 'system', $admin, 'Waiting for customer information');
            }

            return $request;
        });

        if ($admin) {
            Audit::log('order.information_requested', $order, after: ['questions' => array_column($normalized, 'question')], admin: $admin);
        }

        $this->mailer->send(
            EmailTemplateKey::InformationRequired,
            $order->email,
            OrderEmailVariables::for($order) + ['questions' => array_column($normalized, 'question')],
            $order,
        );

        return $request;
    }

    /**
     * Store the customer's answers and resume processing.
     *
     * @param  array<string, string>  $answers  keyed by question key (q1, q2...)
     */
    public function answer(InformationRequest $request, array $answers): void
    {
        if (! $request->isOpen()) {
            throw new InvalidArgumentException('This question has already been answered.');
        }

        $clean = [];
        foreach ($request->questions as $question) {
            $value = trim((string) ($answers[$question['key']] ?? ''));
            if ($value !== '') {
                $clean[$question['key']] = mb_substr($value, 0, 3000);
            }
        }

        if ($clean === []) {
            throw new InvalidArgumentException('Please answer at least one question.');
        }

        $order = $request->order;

        DB::transaction(function () use ($request, $clean, $order) {
            $request->forceFill(['answers' => $clean, 'status' => 'answered', 'answered_at' => now()])->save();

            foreach ($request->questions as $question) {
                if (! isset($clean[$question['key']])) {
                    continue;
                }
                OrderAnswer::query()->updateOrCreate(
                    ['order_id' => $order->id, 'field_key' => 'followup_'.$request->id.'_'.$question['key']],
                    ['label' => $question['question'], 'type' => 'textarea', 'section' => FieldSection::Additional->value, 'value' => $clean[$question['key']]],
                );
            }

            if ($order->status === OrderStatus::NeedsInformation) {
                $this->states->transition($order, OrderStatus::Researching, 'customer', reason: 'Customer provided requested information');
            }
        });

        if ($order->latestAiJob) {
            app(PipelineDispatcher::class)->resume($order, 'information_received');
        }
    }

    /** No answer before the deadline: continue with what we have, or hand to a human. */
    public function expire(InformationRequest $request): void
    {
        if (! $request->isOpen()) {
            return;
        }

        $order = $request->order;
        $request->forceFill(['status' => 'expired'])->save();

        if ($order->status !== OrderStatus::NeedsInformation) {
            return;
        }

        if (Settings::get('orders.needs_info_timeout_action', 'proceed') === 'manual_review') {
            $this->states->transition($order, OrderStatus::ManualReview, 'system', reason: 'Customer did not answer the information request in time');
            app(\App\Domain\Notifications\AdminNotifier::class)->manualReviewRequired($order, 'The customer did not answer the follow-up questions in time.');

            return;
        }

        $this->states->transition($order, OrderStatus::Researching, 'system', reason: 'Information request expired; continuing with available information');
        if ($order->latestAiJob) {
            app(PipelineDispatcher::class)->resume($order, 'information_expired');
        }
    }

    public function remind(InformationRequest $request): void
    {
        if (! $request->isOpen() || $request->reminder_sent_at) {
            return;
        }

        $request->forceFill(['reminder_sent_at' => now()])->save();
        $this->mailer->send(
            EmailTemplateKey::InformationReminder,
            $request->order->email,
            OrderEmailVariables::for($request->order) + ['questions' => array_column($request->questions, 'question')],
            $request->order,
        );
    }
}
