<?php

namespace App\Domain\Maintenance;

use App\Domain\Orders\InformationRequestService;
use App\Models\InformationRequest;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Heartbeat task: sends one reminder for unanswered follow-up questions and,
 * once a request expires, continues with the information available (or hands
 * the order to a person, per orders.needs_info_timeout_action).
 */
class ProcessInformationRequests
{
    public function __construct(private readonly InformationRequestService $requests) {}

    public function __invoke(): void
    {
        InformationRequest::query()
            ->where('status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('order')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(fn (InformationRequest $request) => $this->safely('expire', $request, fn () => $this->requests->expire($request)));

        $remindAfter = max(1, (int) Settings::get('orders.needs_info_reminder_hours', 24));

        InformationRequest::query()
            ->where('status', 'open')
            ->whereNull('reminder_sent_at')
            ->where('requested_at', '<=', now()->subHours($remindAfter))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->addHour()))
            ->with('order')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(fn (InformationRequest $request) => $this->safely('remind', $request, fn () => $this->requests->remind($request)));
    }

    private function safely(string $action, InformationRequest $request, \Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
            Log::error('Information request '.$action.' failed', ['request' => $request->id, 'error' => $e->getMessage()]);
        }
    }
}
