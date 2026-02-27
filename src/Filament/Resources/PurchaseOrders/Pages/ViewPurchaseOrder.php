<?php

namespace SmartTill\Core\Filament\Resources\PurchaseOrders\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;
use SmartTill\Core\Enums\PurchaseOrderStatus;
use SmartTill\Core\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use SmartTill\Core\Services\PurchaseOrderTransactionService;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateInfolistData(array $data): array
    {
        // Eager load variations with product relationship for the repeatable entry
        $this->record->loadMissing([
            'variations.product',
        ]);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Edit')
                ->color('warning')
                ->visible(fn () => $this->record->status !== PurchaseOrderStatus::Closed),
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn () => Route::has('print.purchase-order') && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Print Purchase Orders'))
                ->authorize(fn () => Route::has('print.purchase-order') && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Print Purchase Orders'))
                ->url(fn () => Route::has('print.purchase-order') ? route('print.purchase-order', [
                    'purchaseOrder' => $this->record->id,
                ]) : null)
                ->openUrlInNewTab(),
            Action::make('receive')
                ->label('Mark as Closed')
                ->color('success')
                ->visible(fn () => ! in_array($this->record->status, [PurchaseOrderStatus::Closed, PurchaseOrderStatus::Cancelled], true) && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Close Purchase Orders'))
                ->authorize(fn () => \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Close Purchase Orders'))
                ->url(fn () => route('filament.store.resources.purchase-orders.close', [
                    'tenant' => filament()->getTenant(),
                    'record' => $this->record,
                ]))
                ->requiresConfirmation()
                ->modalHeading('Mark as Closed')
                ->modalDescription('Are you sure you want to mark this purchase order as closed? This action will update stock levels and cannot be undone.')
                ->modalSubmitActionLabel('Yes, Mark as Closed'),
            Action::make('cancel')
                ->label('Mark as Cancelled')
                ->color('danger')
                ->visible(fn () => $this->record->status === PurchaseOrderStatus::Closed && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Cancel Purchase Orders'))
                ->authorize(fn () => \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Cancel Purchase Orders'))
                ->requiresConfirmation()
                ->modalHeading('Mark as Cancelled')
                ->modalDescription('Are you sure you want to mark this purchase order as cancelled? This will reverse stock and supplier transactions for this purchase order.')
                ->action(function (): void {
                    app(PurchaseOrderTransactionService::class)->handlePurchaseOrderCancelled($this->record);
                    $this->record->status = PurchaseOrderStatus::Cancelled;
                    $this->record->save();
                    $this->record->recalculateTotals();

                    Notification::make()
                        ->title('Purchase order marked as cancelled')
                        ->success()
                        ->send();
                }),
            DeleteAction::make()
                ->label('Delete')
                ->color('danger')
                ->visible(fn () => ! $this->record->trashed() && $this->record->status !== PurchaseOrderStatus::Closed),
            RestoreAction::make()
                ->label('Restore')
                ->color('success')
                ->visible(fn () => $this->record->trashed()),
            ForceDeleteAction::make()
                ->label('Force delete')
                ->color('warning')
                ->visible(fn () => $this->record->trashed()),

        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
