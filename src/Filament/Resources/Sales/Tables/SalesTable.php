<?php

namespace SmartTill\Core\Filament\Resources\Sales\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use SmartTill\Core\Enums\SalePaymentStatus;
use SmartTill\Core\Enums\SaleStatus;
use SmartTill\Core\Filament\Exports\SaleExporter;
use SmartTill\Core\Filament\Resources\Customers\CustomerResource;
use SmartTill\Core\Filament\Resources\Customers\RelationManagers\SalesRelationManager;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Filament\Resources\Helpers\SyncReferenceColumn;
use SmartTill\Core\Filament\Resources\Sales\SaleResource;
use SmartTill\Core\Services\SaleTransactionService;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SyncReferenceColumn::make(isToggledHiddenByDefault: false),
                TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(fn ($record) => $record->customer?->name ?? 'Guest')
                    ->description(fn ($record) => $record->customer?->phone ?: null)
                    ->searchable(
                        query: function (Builder $query, string $search) {
                            $query->whereHas('customer', function ($q) use ($search) {
                                $q->where('name', 'like', "%$search%")
                                    ->orWhere('phone', 'like', "%$search%");
                            });
                        }
                    )
                    ->url(fn ($record) => $record->customer ? CustomerResource::getUrl('view', ['record' => $record->customer]) : null)
                    ->color(fn ($record) => $record->customer ? 'primary' : null)
                    ->hiddenOn(SalesRelationManager::class),
                TextColumn::make('total')
                    ->summarize(Sum::make()->label('Total Earning')->money(fn () => Filament::getTenant()?->currency?->code ?? 'PKR', 100))
                    ->money(fn () => Filament::getTenant()?->currency?->code ?? 'PKR'),
                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('updated_at')
                    ->label('Updated at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->updated_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->created_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A'))
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(SaleStatus::class)
                    ->multiple()
                    ->preload(),
                SelectFilter::make('payment_status')
                    ->options(fn () => collect(SalePaymentStatus::cases())
                        ->filter(fn ($status) => $status !== SalePaymentStatus::Refunded)
                        ->mapWithKeys(fn ($status) => [$status->value => $status->getLabel()])
                        ->toArray())
                    ->multiple(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label('View')
                        ->color('primary'),
                    EditAction::make()
                        ->label('Edit')
                        ->color('warning'),
                    Action::make('print')
                        ->label('Print')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->color('gray')
                        ->visible(fn () => ResourceCanAccessHelper::check('Print Sales'))
                        ->authorize(fn () => ResourceCanAccessHelper::check('Print Sales'))
                        ->action(function ($record) {
                            if (! Route::has('public.receipt')) {
                                return redirect()->to(SaleResource::getUrl());
                            }

                            return redirect()->route('public.receipt', [
                                'store' => $record->store?->slug,
                                'reference' => $record->reference,
                                'print' => 1,
                                'next' => SaleResource::getUrl(),
                            ])->with([
                                'print.next' => SaleResource::getUrl(),
                                'print.mode' => true,
                            ]);
                        }),
                    Action::make('manageFbrInvoice')
                        ->label(fn ($record) => match (true) {
                            $record->status === SaleStatus::Cancelled && ! empty($record->fbr_invoice_number) && empty($record->fbr_refund_invoice_number) => 'Generate FBR Refund Invoice',
                            $record->use_fbr && empty($record->fbr_invoice_number) => 'Regenerate FBR Invoice',
                            default => 'Generate FBR Invoice',
                        })
                        ->icon(fn ($record) => match (true) {
                            $record->status === SaleStatus::Cancelled && ! empty($record->fbr_invoice_number) && empty($record->fbr_refund_invoice_number) => Heroicon::OutlinedArrowPath,
                            $record->use_fbr && empty($record->fbr_invoice_number) => Heroicon::OutlinedArrowPath,
                            default => Heroicon::OutlinedDocumentPlus,
                        })
                        ->color(fn ($record) => match (true) {
                            $record->status === SaleStatus::Cancelled && ! empty($record->fbr_invoice_number) && empty($record->fbr_refund_invoice_number) => 'danger',
                            $record->use_fbr && empty($record->fbr_invoice_number) => 'warning',
                            default => 'success',
                        })
                        ->visible(function ($record) {
                            if (! ResourceCanAccessHelper::check('Manage FBR Invoices')) {
                                return false;
                            }

                            // FBR is only available for Pakistan stores
                            if (($record->store?->country?->code ?? null) !== 'PK') {
                                return false;
                            }

                            $hasFbrConfig = ! empty($record->store->fbr_sandbox_pos_id) || ! empty($record->store->fbr_pos_id);

                            if (! $hasFbrConfig) {
                                return false;
                            }

                            // Show for refund invoice generation
                            if ($record->status === SaleStatus::Cancelled && ! empty($record->fbr_invoice_number) && empty($record->fbr_refund_invoice_number)) {
                                return true;
                            }

                            // Show for invoice generation/regeneration
                            if ($record->status === SaleStatus::Completed && empty($record->fbr_invoice_number)) {
                                return true;
                            }

                            return false;
                        })
                        ->authorize(fn () => ResourceCanAccessHelper::check('Manage FBR Invoices'))
                        ->requiresConfirmation()
                        ->modalHeading(fn ($record) => match (true) {
                            $record->status === SaleStatus::Cancelled && ! empty($record->fbr_invoice_number) && empty($record->fbr_refund_invoice_number) => 'Generate FBR Refund Invoice',
                            $record->use_fbr && empty($record->fbr_invoice_number) => 'Regenerate FBR Invoice',
                            default => 'Generate FBR Invoice',
                        })
                        ->modalDescription(fn ($record) => match (true) {
                            $record->status === SaleStatus::Cancelled && ! empty($record->fbr_invoice_number) && empty($record->fbr_refund_invoice_number) => 'This will attempt to generate an FBR refund invoice for this cancelled sale.',
                            $record->use_fbr && empty($record->fbr_invoice_number) => 'This will attempt to generate an FBR invoice for this sale.',
                            default => 'This will enable FBR for this sale and generate an FBR invoice.',
                        })
                        ->action(function ($record) {
                            try {
                                $saleTransactionService = app(SaleTransactionService::class);

                                // Determine which action to perform
                                if ($record->status === SaleStatus::Cancelled && ! empty($record->fbr_invoice_number) && empty($record->fbr_refund_invoice_number)) {
                                    // Generate refund invoice
                                    $saleTransactionService->generateFbrRefundInvoice($record);
                                    $record->refresh();

                                    if ($record->fbr_refund_invoice_number) {
                                        Notification::make()
                                            ->title('FBR Refund Invoice Generated')
                                            ->body('Refund Invoice Number: '.$record->fbr_refund_invoice_number)
                                            ->success()
                                            ->send();
                                    } else {
                                        // Handle refund invoice error
                                        $errorMessage = 'FBR refund invoice generation failed.';
                                        $errorCode = null;

                                        if ($record->fbr_response) {
                                            if (isset($record->fbr_response['error'])) {
                                                $errorMessage = $record->fbr_response['error'];
                                                $errorCode = $record->fbr_response['code'] ?? null;
                                            } elseif (isset($record->fbr_response['response']['Response'])) {
                                                $errorMessage = $record->fbr_response['response']['Response'];
                                                $errorCode = $record->fbr_response['response']['Code'] ?? null;
                                            } elseif (isset($record->fbr_response['refund_response']['Response'])) {
                                                $errorMessage = $record->fbr_response['refund_response']['Response'];
                                                $errorCode = $record->fbr_response['refund_response']['Code'] ?? null;
                                            }
                                        } elseif (! $record->store->fbr_pos_id && ! $record->store->fbr_sandbox_pos_id) {
                                            $errorMessage = 'FBR POS ID is not configured for this store.';
                                        } elseif ($record->store->fbr_environment->value === 'production' && ! $record->store->fbr_bearer_token) {
                                            $errorMessage = 'FBR Bearer Token is not configured for production environment.';
                                        }

                                        if ($errorCode) {
                                            $errorMessage = "Code {$errorCode}: {$errorMessage}";
                                        }

                                        Notification::make()
                                            ->title('FBR Refund Invoice Generation Failed')
                                            ->body($errorMessage)
                                            ->danger()
                                            ->duration(10000)
                                            ->send();
                                    }
                                } else {
                                    // Generate or regenerate invoice
                                    if (! $record->use_fbr) {
                                        $record->update(['use_fbr' => true]);
                                    }

                                    $saleTransactionService->generateFbrInvoice($record);
                                    $record->refresh();

                                    if ($record->fbr_invoice_number) {
                                        Notification::make()
                                            ->title('FBR Invoice Generated')
                                            ->body('Invoice Number: '.$record->fbr_invoice_number)
                                            ->success()
                                            ->send();
                                    } else {
                                        // Handle invoice error
                                        $errorMessage = 'FBR invoice generation failed.';
                                        $errorCode = null;

                                        if ($record->fbr_response) {
                                            if (isset($record->fbr_response['error'])) {
                                                $errorMessage = $record->fbr_response['error'];
                                                $errorCode = $record->fbr_response['code'] ?? null;
                                            } elseif (isset($record->fbr_response['response']['Response'])) {
                                                $errorMessage = $record->fbr_response['response']['Response'];
                                                $errorCode = $record->fbr_response['response']['Code'] ?? null;
                                            }
                                        } elseif (! $record->store->fbr_pos_id && ! $record->store->fbr_sandbox_pos_id) {
                                            $errorMessage = 'FBR POS ID is not configured for this store.';
                                        } elseif ($record->store->fbr_environment->value === 'production' && ! $record->store->fbr_bearer_token) {
                                            $errorMessage = 'FBR Bearer Token is not configured for production environment.';
                                        }

                                        if ($errorCode) {
                                            $errorMessage = "Code {$errorCode}: {$errorMessage}";
                                        }

                                        Notification::make()
                                            ->title('FBR Invoice Generation Failed')
                                            ->body($errorMessage)
                                            ->danger()
                                            ->duration(10000)
                                            ->send();
                                    }
                                }
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Error Generating FBR Invoice')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->duration(10000)
                                    ->send();
                            }
                        }),
                    Action::make('markAsCancelled')
                        ->label('Mark as Cancelled')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('danger')
                        ->visible(fn ($record) => $record->status !== SaleStatus::Cancelled && ResourceCanAccessHelper::check('Mark Sales as Cancelled'))
                        ->authorize(fn () => ResourceCanAccessHelper::check('Mark Sales as Cancelled'))
                        ->requiresConfirmation()
                        ->action(function ($record) {
                            app(SaleTransactionService::class)->handleSaleOnCancelled($record);
                            Notification::make()
                                ->title('Order cancelled')
                                ->success()
                                ->send();
                        }),
                    DeleteAction::make()
                        ->label('Delete')
                        ->color('danger')
                        ->visible(fn ($record) => $record->status === SaleStatus::Pending),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ExportBulkAction::make()
                        ->exporter(SaleExporter::class)
                        ->visible(fn () => ResourceCanAccessHelper::check('Export Sales'))
                        ->authorize(fn () => ResourceCanAccessHelper::check('Export Sales')),
                ]),
            ]);
    }
}
