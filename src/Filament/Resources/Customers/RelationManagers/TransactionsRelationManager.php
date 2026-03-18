<?php

namespace SmartTill\Core\Filament\Resources\Customers\RelationManagers;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use SmartTill\Core\Models\Customer;
use League\Csv\Bom;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use SmartTill\Core\Enums\PaymentMethod;
use SmartTill\Core\Enums\SalePaymentStatus;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Filament\Resources\Transactions\Tables\TransactionsTable;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\Transaction;
use SmartTill\Core\Services\PaymentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsRelationManager extends RelationManager
{
    public const PAID_SALE_REFERENCE_TYPE = 'paid_sale_reference';

    protected static string $relationship = 'transactions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ResourceCanAccessHelper::check('View Customer Transactions');
    }

    public function table(Table $table): Table
    {
        return TransactionsTable::configure($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->includePaidSalesInTableQuery($query)->with('referenceable'))
            ->columns([
                TextColumn::make('referenceable')
                    ->label('Reference')
                    ->description(
                        fn (Transaction $record): string => $record->type === self::PAID_SALE_REFERENCE_TYPE
                            ? 'Sale'
                            : class_basename((string) $record->referenceable_type),
                        'above'
                    )
                    ->color('primary')
                    ->prefix('#')
                    ->formatStateUsing(fn (Transaction $record) => $record->referenceable?->reference ?? $record->referenceable_id)
                    ->url(function (Transaction $record): ?string {
                        if ($record->referenceable instanceof Sale) {
                            return \SmartTill\Core\Filament\Resources\Sales\SaleResource::getUrl('view', ['record' => $record->referenceable]);
                        }

                        return null;
                    }),
                TextColumn::make('note')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->created_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A')),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->getStateUsing(fn (Transaction $record): float => abs((float) $record->amount))
                    ->money(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                    ->icon(fn (Transaction $record): ?Heroicon => match ($record->type) {
                        'customer_credit' => Heroicon::OutlinedArrowUp,
                        'customer_debit' => Heroicon::OutlinedArrowDown,
                        default => null,
                    })
                    ->iconColor(function (Transaction $record): ?string {
                        return match ($record->type) {
                            'customer_credit' => 'success',
                            'customer_debit' => 'danger',
                            default => null,
                        };
                    })
                    ->color(function (Transaction $record): ?string {
                        return match ($record->type) {
                            'customer_credit' => 'success',
                            'customer_debit' => 'danger',
                            default => null,
                        };
                    }),
                TextColumn::make('amount_balance')
                    ->label('Balance')
                    ->state(fn (Transaction $record) => $record->type === self::PAID_SALE_REFERENCE_TYPE ? null : $record->amount_balance)
                    ->money(fn () => Filament::getTenant()?->currency->code ?? 'PKR')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'customer_debit' => 'Debit',
                        'customer_credit' => 'Credit',
                        self::PAID_SALE_REFERENCE_TYPE => 'Paid Sale',
                    ])
                    ->multiple()
                    ->preload(),

                Filter::make('created_at_range')
                    ->label('Date range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                filled($data['from'] ?? null),
                                fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $data['from'])
                            )
                            ->when(
                                filled($data['until'] ?? null),
                                fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $data['until'])
                            );
                    }),
            ])
            ->headerActions([
                Action::make('receive')
                    ->label('Receive Payment')
                    ->visible(fn () => ResourceCanAccessHelper::check('Receive Payment from Customers'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Receive Payment from Customers'))
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('amount')
                                    ->label('Amount')
                                    ->numeric()
                                    ->required()
                                    ->helperText('Positive = Payment received, Negative = Receivable added')
                                    ->placeholder('Enter positive for payment, negative for receivable')
                                    ->prefix(fn () => Filament::getTenant()?->currency->code ?? 'PKR'),
                                Select::make('payment_method')
                                    ->label('Payment Method')
                                    ->options(PaymentMethod::class)
                                    ->default(PaymentMethod::Cash)
                                    ->required()
                                    ->enum(PaymentMethod::class),
                                Textarea::make('note')
                                    ->label('Note')
                                    ->maxLength(50)
                                    ->helperText('Up to 50 characters.')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->action(function (array $data, RelationManager $livewire) {
                        $customer = $livewire->getOwnerRecord();
                        if (! array_key_exists('amount', $data) || ! array_key_exists('payment_method', $data)) {
                            Notification::make()
                                ->title('Missing payment details')
                                ->body('Please provide amount and payment method.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            app(PaymentService::class)->recordPayment(
                                payable: $customer,
                                amount: $data['amount'],
                                paymentMethod: $data['payment_method'],
                                note: $data['note'] ?? null
                            );

                            $isReceivable = $data['amount'] < 0;
                            Notification::make()
                                ->title($isReceivable ? 'Receivable added successfully' : 'Payment received successfully')
                                ->body($isReceivable ? 'Receivable recorded and transaction entry created' : 'Payment recorded and transaction entry created')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to record payment')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->icon(Heroicon::OutlinedArrowDown),
                Action::make('exportLedger')
                    ->label('Export Ledger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn () => ResourceCanAccessHelper::check('Export Sales'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Export Sales'))
                    ->schema([
                        Select::make('format')
                            ->label('Format')
                            ->options([
                                'xlsx' => 'Excel (.xlsx)',
                                'csv' => 'CSV (.csv)',
                            ])
                            ->default('xlsx')
                            ->required()
                            ->native(false),
                        Checkbox::make('include_paid_sales')
                            ->label('Include paid sales')
                            ->helperText('Some paid sales exist for this customer. Enable this to include them as reference rows without changing ledger balances.')
                            ->visible(fn (RelationManager $livewire): bool => $livewire->hasPaidSalesForExport())
                            ->default(false),
                    ])
                    ->action(fn (array $data, RelationManager $livewire) => $livewire->downloadLedgerReport(
                        $data['format'] ?? 'xlsx',
                        (bool) ($data['include_paid_sales'] ?? false),
                    )),
            ])
            ->toolbarActions([]);
    }

    protected function includePaidSalesInTableQuery(Builder $query): Builder
    {
        $transactionsBaseQuery = (clone $query)
            ->select('transactions.*')
            ->getQuery();

        $transactionsBaseQuery->unionAll(
            $this->getPaidSalesQueryForTable()->getQuery()
        );

        return Transaction::query()
            ->fromSub($transactionsBaseQuery, 'transactions')
            ->select('transactions.*');
    }

    public function downloadLedgerReport(string $format, bool $includePaidSales = false): StreamedResponse
    {
        $customer = $this->getOwnerRecord();
        $store = Filament::getTenant();
        $timezone = $store?->timezone?->name ?? config('app.timezone', 'UTC');
        $decimalPlaces = $store?->currency?->decimal_places ?? 2;

        $metadataRows = [
            ['Store Name', $store?->business_name ?: $store?->name ?: '—'],
            ['Store Phone', $store?->phone ?: '—'],
            ['Store Email', $store?->email ?: '—'],
            ['Customer Name', $customer->name ?: '—'],
            ['Customer Phone', $customer->phone ?: '—'],
            ['Customer Email', $customer->email ?: '—'],
            ['Generated At', now()->setTimezone($timezone)->format('M d, Y g:i A')],
            [],
        ];

        $ledgerHeaderRow = ['Date', 'Reference', 'Note', 'Type', 'Amount', 'Balance'];
        $fileBaseName = Str::slug(($store?->name ?: 'store').'-'.($customer->name ?: 'customer').'-ledger-'.now()->format('Y-m-d-His'));

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($metadataRows, $ledgerHeaderRow, $timezone, $decimalPlaces, $includePaidSales): void {
                $handle = fopen('php://output', 'wb');
                if ($handle === false) {
                    return;
                }

                fwrite($handle, Bom::Utf8->value);

                foreach ($metadataRows as $row) {
                    fputcsv($handle, $this->csvMetadataRow($row));
                }

                fputcsv($handle, $ledgerHeaderRow);

                foreach ($this->ledgerRows($timezone, $decimalPlaces, $includePaidSales) as $row) {
                    fputcsv($handle, $row);
                }

                fclose($handle);
            }, "{$fileBaseName}.csv", [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return response()->streamDownload(function () use ($fileBaseName, $metadataRows, $ledgerHeaderRow, $timezone, $decimalPlaces, $includePaidSales): void {
            $writer = app(XlsxWriter::class);
            $writer->openToBrowser("{$fileBaseName}.xlsx");

            foreach ($metadataRows as $row) {
                $writer->addRow($this->xlsxMetadataRow($row));
            }

            $writer->addRow(Row::fromValues($ledgerHeaderRow));

            foreach ($this->ledgerRows($timezone, $decimalPlaces, $includePaidSales) as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();
        }, "{$fileBaseName}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return \Generator<int, array<int, string>>
     */
    protected function ledgerRows(string $timezone, int $decimalPlaces, bool $includePaidSales = false): \Generator
    {
        $referenceCache = [];
        $transactionIterator = $this->getTableQueryForExport()
            ->reorder('created_at')
            ->orderBy('id')
            ->cursor()
            ->getIterator();
        $saleIterator = ($includePaidSales ? $this->getPaidSalesQueryForExport() : Sale::query()->whereRaw('1 = 0'))
            ->cursor()
            ->getIterator();

        $transactionIterator->rewind();
        $saleIterator->rewind();

        while ($transactionIterator->valid() || $saleIterator->valid()) {
            $transaction = $transactionIterator->valid() ? $transactionIterator->current() : null;
            $sale = $saleIterator->valid() ? $saleIterator->current() : null;

            if ($this->shouldYieldTransactionFirst($transaction, $sale)) {
                yield [
                    $transaction->created_at?->setTimezone($timezone)->format('M d, Y g:i A'),
                    $this->resolveReferenceSummary($transaction, $referenceCache),
                    $transaction->note ?: '—',
                    Str::headline((string) $transaction->type),
                    Number::format((float) $transaction->amount, $decimalPlaces),
                    Number::format((float) $transaction->amount_balance, $decimalPlaces),
                ];

                $transactionIterator->next();

                continue;
            }

            yield [
                $this->saleLedgerTimestamp($sale)?->setTimezone($timezone)->format('M d, Y g:i A'),
                $this->resolveSaleReferenceSummary($sale),
                $sale->note ?: 'Paid sale (informational only)',
                'Paid Sale',
                Number::format((float) $sale->total, $decimalPlaces),
                '—',
            ];

            $saleIterator->next();
        }
    }

    protected function getPaidSalesQueryForExport(): HasMany
    {
        /** @var \SmartTill\Core\Models\Customer $customer */
        $customer = $this->getOwnerRecord();

        return $customer->sales()
            ->where('payment_status', SalePaymentStatus::Paid)
            ->orderByRaw('COALESCE(paid_at, created_at) asc')
            ->orderBy('id');
    }

    protected function getPaidSalesQueryForTable(): HasMany
    {
        /** @var \SmartTill\Core\Models\Customer $customer */
        $customer = $this->getOwnerRecord();

        return $customer->sales()
            ->where('payment_status', SalePaymentStatus::Paid)
            ->selectRaw('(1000000000 + sales.id) as id')
            ->selectRaw('sales.store_id')
            ->selectRaw('? as transactionable_type', [Customer::class])
            ->selectRaw('sales.customer_id as transactionable_id')
            ->selectRaw('? as referenceable_type', [Sale::class])
            ->selectRaw('sales.id as referenceable_id')
            ->selectRaw('? as type', [self::PAID_SALE_REFERENCE_TYPE])
            ->selectRaw('sales.total as amount')
            ->selectRaw('null as amount_balance')
            ->selectRaw('null as quantity')
            ->selectRaw('null as quantity_balance')
            ->selectRaw("COALESCE(sales.note, 'Paid sale (informational only)') as note")
            ->selectRaw('null as meta')
            ->selectRaw('null as deleted_at')
            ->selectRaw('COALESCE(sales.paid_at, sales.created_at) as created_at')
            ->selectRaw('sales.updated_at as updated_at')
            ->selectRaw('null as local_id')
            ->selectRaw('null as reference');
    }

    protected function hasPaidSalesForExport(): bool
    {
        return $this->getPaidSalesQueryForExport()->exists();
    }

    protected function shouldYieldTransactionFirst(?Transaction $transaction, ?Sale $sale): bool
    {
        if ($transaction === null) {
            return false;
        }

        if ($sale === null) {
            return true;
        }

        $transactionTimestamp = $transaction->created_at?->getTimestamp() ?? PHP_INT_MAX;
        $saleTimestamp = $this->saleLedgerTimestamp($sale)?->getTimestamp() ?? PHP_INT_MAX;

        if ($transactionTimestamp !== $saleTimestamp) {
            return $transactionTimestamp < $saleTimestamp;
        }

        return $transaction->id <= $sale->id;
    }

    protected function saleLedgerTimestamp(Sale $sale): ?\Illuminate\Support\Carbon
    {
        return $sale->paid_at ?? $sale->created_at;
    }

    protected function resolveSaleReferenceSummary(Sale $sale): string
    {
        $referenceValue = $sale->reference ?: $sale->id;

        return "Sale #{$referenceValue}";
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, string>
     */
    protected function csvMetadataRow(array $row): array
    {
        if ($row === []) {
            return [];
        }

        $label = (string) ($row[0] ?? '');
        $value = (string) ($row[1] ?? '');

        if (str_contains($label, 'Phone') && $value !== '—') {
            $value = '="'.$value.'"';
        }

        return [$label, $value];
    }

    /**
     * @param  array<int, mixed>  $row
     */
    protected function xlsxMetadataRow(array $row): Row
    {
        if ($row === []) {
            return Row::fromValues([]);
        }

        return new Row([
            new StringCell((string) ($row[0] ?? ''), null),
            new StringCell((string) ($row[1] ?? ''), null),
        ]);
    }

    /**
     * @param  array<string, string>  $referenceCache
     */
    protected function resolveReferenceSummary(Transaction $record, array &$referenceCache): string
    {
        if (! filled($record->referenceable_type) || ! filled($record->referenceable_id)) {
            return '—';
        }

        $cacheKey = $record->referenceable_type.'#'.$record->referenceable_id;

        if (array_key_exists($cacheKey, $referenceCache)) {
            return $referenceCache[$cacheKey];
        }

        $referenceType = class_basename((string) $record->referenceable_type);
        $referenceable = $record->referenceable()->getResults();
        $referenceValue = $referenceable?->reference
            ?? $record->referenceable_id;

        return $referenceCache[$cacheKey] = filled($referenceValue)
            ? "{$referenceType} #{$referenceValue}"
            : '—';
    }

    protected function resolveReferenceSummaryForTable(Transaction $record): string
    {
        if ($record->type === self::PAID_SALE_REFERENCE_TYPE && $record->referenceable instanceof Sale) {
            return $this->resolveSaleReferenceSummary($record->referenceable);
        }

        if (! filled($record->referenceable_type) || ! filled($record->referenceable_id)) {
            return '—';
        }

        $referenceType = class_basename((string) $record->referenceable_type);
        $referenceable = $record->referenceable;
        $referenceValue = $referenceable?->reference ?? $record->referenceable_id;

        return filled($referenceValue)
            ? "{$referenceType} #{$referenceValue}"
            : '—';
    }
}
