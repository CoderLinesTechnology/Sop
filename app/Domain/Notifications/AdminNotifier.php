<?php

namespace App\Domain\Notifications;

use App\Enums\Permission;
use App\Mail\AdminAlertMail;
use App\Models\AdminUser;
use App\Models\Order;
use App\Support\AdminUrls;
use App\Support\Runtime\AfterResponse;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifies administrators about operational events: an in-panel database
 * notification for admins with the relevant permission, plus email (and an
 * optional chat webhook) for anything that needs attention.
 */
class AdminNotifier
{
    public function paymentSucceeded(Order $order): void
    {
        $this->notify('payment_succeeded', "Payment received · {$order->reference}",
            "{$order->serviceName()} — {$order->formattedTotal()} from {$order->email}.", $order, 'success', email: false);
    }

    public function documentReady(Order $order): void
    {
        $this->notify('document_ready', "Document delivered · {$order->reference}",
            "{$order->serviceName()} for {$order->applicationTitle()} was delivered to the customer.", $order, 'success', email: false);
    }

    public function aiJobFailed(Order $order, string $reason): void
    {
        $this->notify('ai_failed', "AI processing needs attention · {$order->reference}", $reason, $order, 'danger',
            permissions: [Permission::OrdersManage, Permission::AiManage]);
    }

    public function aiJobSlow(Order $order, int $minutes): void
    {
        $this->notify('ai_slow', "Order taking longer than expected · {$order->reference}",
            "Processing has been running for {$minutes} minutes.", $order, 'warning',
            permissions: [Permission::OrdersManage, Permission::AiManage]);
    }

    public function manualReviewRequired(Order $order, string $reason): void
    {
        $this->notify('manual_review', "Manual review required · {$order->reference}", $reason, $order, 'warning',
            permissions: [Permission::OrdersManage, Permission::AiManage]);
    }

    public function deliveryFailed(Order $order, string $reason): void
    {
        $this->notify('delivery_failed', "Email delivery failed · {$order->reference}", $reason, $order, 'danger',
            permissions: [Permission::OrdersManage, Permission::EmailsManage]);
    }

    public function revisionRequested(Order $order, int $number): void
    {
        $this->notify('revision_requested', "Revision #{$number} requested · {$order->reference}",
            'The customer asked for changes to their document.', $order, 'info',
            permissions: [Permission::OrdersManage, Permission::RevisionsManage]);
    }

    public function refundRequested(Order $order, string $amount, string $reason): void
    {
        $this->notify('refund_requested', "Refund requested · {$order->reference}", "{$amount}: {$reason}", $order, 'warning',
            permissions: [Permission::RefundsApprove]);
    }

    public function supportMessage(string $from, ?Order $order): void
    {
        $this->notify('support_message', 'New support message', "From {$from}".($order ? " about {$order->reference}" : '').'.', $order, 'info',
            permissions: [Permission::SupportManage], email: false);
    }

    public function suspiciousActivity(string $type, array $details, ?Order $order = null): void
    {
        $summary = collect($details)->take(4)->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode('; ');
        $this->notify('suspicious', 'Suspicious activity: '.str_replace('_', ' ', $type), $summary ?: 'See security events.', $order, 'danger',
            permissions: [Permission::AuditView, Permission::OrdersManage]);
    }

    /**
     * @param  list<string>  $permissions  recipients need any of these permissions
     */
    public function notify(
        string $event,
        string $title,
        string $body,
        ?Order $order = null,
        string $level = 'info',
        array $permissions = [Permission::OrdersView],
        bool $email = true,
    ): void {
        try {
            $recipients = AdminUser::query()
                ->where('is_active', true)
                ->where(function ($query) use ($permissions) {
                    $query->permission($permissions);
                })
                ->get();

            if ($recipients->isNotEmpty()) {
                $notification = Notification::make()
                    ->title($title)
                    ->body($body)
                    ->status($level === 'danger' ? 'danger' : ($level === 'warning' ? 'warning' : ($level === 'success' ? 'success' : 'info')));

                if ($order) {
                    $notification->actions([
                        Action::make('view')->label('View order')->url(AdminUrls::order($order))->markAsRead(),
                    ]);
                }

                $notification->sendToDatabase($recipients);
            }
        } catch (Throwable $e) {
            Log::error('Admin database notification failed', ['event' => $event, 'error' => $e->getMessage()]);
        }

        if ($email && in_array($level, ['warning', 'danger'], true)) {
            $url = $order ? AdminUrls::order($order) : null;
            foreach (Settings::adminNotificationEmails() as $address) {
                AfterResponse::run('admin-alert:'.$event.':'.$address, function () use ($address, $title, $body, $url, $event) {
                    try {
                        Mail::to($address)->send(new AdminAlertMail($title, $body, $url));
                    } catch (Throwable $e) {
                        Log::error('Admin alert email failed', ['event' => $event, 'error' => $e->getMessage()]);
                    }
                }, timeLimitSeconds: 60);
            }
        }

        $webhook = config('statementra.monitoring.alert_webhook_url');
        if ($webhook && in_array($level, ['warning', 'danger'], true)) {
            try {
                Http::timeout(5)->post($webhook, ['text' => "[{$level}] {$title}\n{$body}".($order ? "\n".AdminUrls::order($order) : '')]);
            } catch (Throwable $e) {
                Log::warning('Alert webhook failed', ['error' => $e->getMessage()]);
            }
        }

        Log::info('admin.notify', ['event' => $event, 'title' => $title, 'order' => $order?->reference]);
    }
}
