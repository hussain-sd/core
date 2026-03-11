<?php

namespace SmartTill\Core\Filament\Resources\Customers\RelationManagers;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use League\Csv\Bom;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use SmartTill\Core\Enums\PaymentMethod;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Filament\Resources\Transactions\Tables\TransactionsTable;
use SmartTill\Core\Models\Transaction;
use SmartTill\Core\Services\PaymentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ResourceCanAccessHelper::check('View Customer Transactions');
    }

    public function table(Table $table): Table
    {
        return TransactionsTable::configure($table)
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'customer_debit' => 'Debit',
                        'customer_credit' => 'Credit',
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
                    ])
                    ->action(fn (array $data, RelationManager $livewire) => $livewire->downloadLedgerReport($data['format'] ?? 'xlsx')),
            ])
            ->toolbarActions([]);
    }

    public function downloadLedgerReport(string $format): StreamedResponse
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
            return response()->streamDownload(function () use ($metadataRows, $ledgerHeaderRow, $timezone, $decimalPlaces): void {
                $handle = fopen('php://output', 'wb');
                if ($handle === false) {
                    return;
                }

                fwrite($handle, Bom::Utf8->value);

                foreach ($metadataRows as $row) {
                    fputcsv($handle, $this->csvMetadataRow($row));
                }

                fputcsv($handle, $ledgerHeaderRow);

                foreach ($this->ledgerRows($timezone, $decimalPlaces) as $row) {
                    fputcsv($handle, $row);
                }

                fclose($handle);
            }, "{$fileBaseName}.csv", [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return response()->streamDownload(function () use ($fileBaseName, $metadataRows, $ledgerHeaderRow, $timezone, $decimalPlaces): void {
            $writer = app(XlsxWriter::class);
            $writer->openToBrowser("{$fileBaseName}.xlsx");

            foreach ($metadataRows as $row) {
                $writer->addRow($this->xlsxMetadataRow($row));
            }

            $writer->addRow(Row::fromValues($ledgerHeaderRow));

            foreach ($this->ledgerRows($timezone, $decimalPlaces) as $row) {
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
    protected function ledgerRows(string $timezone, int $decimalPlaces): \Generator
    {
        $referenceCache = [];

        foreach ($this->getTableQueryForExport()->reorder('created_at')->orderBy('id')->cursor() as $record) {
            yield [
                $record->created_at?->setTimezone($timezone)->format('M d, Y g:i A'),
                $this->resolveReferenceSummary($record, $referenceCache),
                $record->note ?: '—',
                Str::headline((string) $record->type),
                Number::format((float) $record->amount, $decimalPlaces),
                Number::format((float) $record->amount_balance, $decimalPlaces),
            ];
        }
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
            ?? $referenceable?->local_id
            ?? $record->referenceable_id;

        return $referenceCache[$cacheKey] = filled($referenceValue)
            ? "{$referenceType} #{$referenceValue}"
            : '—';
    }
}
