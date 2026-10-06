<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\CustomerEmailActions;
use App\Filament\Resources\Orders\Actions\DocumentActions;
use App\Filament\Resources\Orders\Actions\OrderManagementActions;
use App\Filament\Resources\Orders\Actions\ProcessingActions;
use App\Filament\Resources\Orders\Actions\RefundOrderAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Operations\OperationsAudit;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property-read Order $record
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        OperationsAudit::orderViewed($this->getRecord());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Order '.$this->getRecord()->reference;
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Order $order */
        $order = $this->getRecord();

        return collect([
            $order->serviceName(),
            $order->applicationTitle() !== $order->serviceName() ? $order->applicationTitle() : null,
            $order->customer_name ?: $order->email,
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(ProcessingActions::all())
                ->label('Processing')
                ->icon(Heroicon::OutlinedCpuChip)
                ->button()
                ->color('gray'),
            ActionGroup::make(OrderManagementActions::all())
                ->label('Order')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->button()
                ->color('gray'),
            ActionGroup::make(CustomerEmailActions::all())
                ->label('Emails')
                ->icon(Heroicon::OutlinedEnvelope)
                ->button()
                ->color('gray'),
            ActionGroup::make(DocumentActions::all())
                ->label('Documents')
                ->icon(Heroicon::OutlinedDocumentText)
                ->button()
                ->color('gray'),
            RefundOrderAction::make(),
        ];
    }

    /**
     * Actions change the order through domain services; reload it (and drop
     * relations loaded earlier in the request) so the page shows the result.
     */
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);

        $order = $this->getRecord();

        if ($order->exists) {
            $order->unsetRelations();
            $order->refresh();
        }
    }
}
