<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Domain\Orders\InformationRequestService;
use App\Domain\Orders\OrderAccess;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderInsights;
use App\Filament\Support\Operations\ActionRunner;
use App\Filament\Support\Operations\AdminContext;
use App\Filament\Support\Operations\OperationsAudit;
use App\Models\Order;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/** Status changes, information requests, internal notes and customer-link rotation. */
final class OrderManagementActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::changeStatus(),
            self::requestInformation(),
            self::addNote(),
            self::rotateCustomerLink(),
        ];
    }

    /**
     * Only transitions the state machine allows; administrators with
     * orders.override may force any status. A reason is always required and
     * is stored in the status history and the audit log.
     */
    public static function changeStatus(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::allowedTransitions($record) !== [] || AdminContext::allows('override', $record))
            ->modalHeading('Change order status')
            ->modalDescription(fn (Order $record): string => 'Current status: '.$record->status->getLabel().'. The change is recorded in the timeline and audit log. Changing the status does not start, stop or refund anything by itself.')
            ->schema(fn (Order $record): array => [
                Toggle::make('force')
                    ->label('Override allowed transitions')
                    ->helperText('Any status can be chosen. Use only to repair an order; the override is audited.')
                    ->live()
                    ->visible(AdminContext::allows('override', $record)),
                Select::make('status')
                    ->label('New status')
                    ->options(fn (Get $get): array => self::statusOptions($record, (bool) $get('force')))
                    ->required()
                    ->native(false),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->modalSubmitActionLabel('Change status')
            ->action(function (array $data, Order $record, Action $action): void {
                $target = OrderStatus::from($data['status']);
                $force = (bool) ($data['force'] ?? false);
                $from = $record->status;

                ActionRunner::run(
                    function () use ($record, $target, $force, $from, $data) {
                        if ($force && ! AdminContext::allows('override', $record)) {
                            throw new AuthorizationException('Only administrators with the override permission can force a status.');
                        }

                        if (! $force && ! in_array($target, OrderInsights::allowedTransitions($record), true)) {
                            throw new RuntimeException("An order cannot move from {$from->getLabel()} to {$target->getLabel()}.");
                        }

                        return OperationsAudit::ensure(
                            $force ? 'order.status_forced' : 'order.status_changed',
                            $record,
                            fn () => app(OrderStateMachine::class)->transition($record, $target, 'admin', AdminContext::require(), $data['reason'], ['forced' => $force], force: $force),
                            meta: ['reason' => $data['reason'], 'forced' => $force],
                            before: ['status' => $from->value],
                            after: ['status' => $target->value],
                        );
                    },
                    'Status changed to '.$target->getLabel(),
                    "Couldn't change the status",
                    keepOpen: $action,
                );
            });
    }

    /** @return array<string, string> */
    private static function statusOptions(Order $order, bool $force): array
    {
        $statuses = $force
            ? array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status !== $order->status)
            : OrderInsights::allowedTransitions($order);

        $options = [];
        foreach ($statuses as $status) {
            $options[$status->value] = $status->getLabel();
        }

        return $options;
    }

    public static function requestInformation(): Action
    {
        return Action::make('requestInformation')
            ->label('Request information')
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->authorize('manage')
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::NeedsInformation
                || in_array(OrderStatus::NeedsInformation, OrderInsights::allowedTransitions($record), true))
            ->modalHeading('Ask the customer for more information')
            ->modalDescription(fn (Order $record): string => $record->email.' receives an email with a secure link to answer. The order waits in "Needs information" until they reply; an open request is replaced.')
            ->schema([
                Repeater::make('questions')
                    ->label('Questions')
                    ->schema([
                        Textarea::make('question')
                            ->label('Question')
                            ->required()
                            ->maxLength(500)
                            ->rows(2),
                        TextInput::make('why')
                            ->label('Why we need it (optional, shown to the customer)')
                            ->maxLength(300),
                    ])
                    ->minItems(1)
                    ->maxItems(InformationRequestService::MAX_QUESTIONS)
                    ->defaultItems(1)
                    ->required()
                    ->reorderable(false)
                    ->addActionLabel('Add another question'),
            ])
            ->modalSubmitActionLabel('Send to customer')
            ->action(fn (array $data, Order $record, Action $action) => ActionRunner::run(
                fn () => OperationsAudit::ensure('order.information_requested', $record,
                    fn () => app(InformationRequestService::class)->request($record, array_values($data['questions']), 'admin', AdminContext::require())),
                'Information requested',
                "Couldn't send the information request",
                'The customer has been emailed. The order resumes when they answer.',
                keepOpen: $action,
            ));
    }

    public static function addNote(): Action
    {
        return Action::make('addNote')
            ->label('Add internal note')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize('addNote')
            ->modalHeading('Add internal note')
            ->modalDescription('Notes are visible to administrators only and are never shown to the customer.')
            ->schema([
                Textarea::make('body')
                    ->label('Note')
                    ->required()
                    ->maxLength(5000)
                    ->rows(5),
            ])
            ->modalSubmitActionLabel('Add note')
            ->action(function (array $data, Order $record): void {
                $note = $record->notes()->create([
                    'admin_user_id' => AdminContext::require()->id,
                    'body' => trim($data['body']),
                ]);

                Audit::log('order.note_added', $record, meta: ['note_id' => $note->id]);

                Notification::make()->success()->title('Note added')->send();
            });
    }

    public static function rotateCustomerLink(): Action
    {
        return Action::make('rotateCustomerLink')
            ->label('Rotate customer link')
            ->icon(Heroicon::OutlinedKey)
            ->color('warning')
            ->authorize('manage')
            ->visible(fn (Order $record): bool => OrderInsights::isSubmitted($record))
            ->requiresConfirmation()
            ->modalHeading('Rotate the customer link?')
            ->modalDescription('Every link previously emailed for this order stops working immediately. Use this when a link was forwarded or shared by mistake.')
            ->schema([
                Toggle::make('send_new_link')
                    ->label('Email the customer a new link')
                    ->default(true),
            ])
            ->modalSubmitActionLabel('Rotate link')
            ->action(fn (array $data, Order $record) => ActionRunner::run(
                function () use ($data, $record) {
                    OperationsAudit::ensure('order.access_links_rotated', $record, fn () => OrderAccess::rotate($record));

                    if ($data['send_new_link'] ?? false) {
                        OperationsAudit::ensure('order.link_resent', $record, fn () => CustomerEmailActions::sendOrderLink($record));
                    }
                },
                'Customer link rotated',
                "Couldn't rotate the customer link",
                ($data['send_new_link'] ?? false) ? 'Old links no longer work. A new link has been emailed.' : 'Old links no longer work.',
            ));
    }
}
