<?php

namespace SmartTill\Core\Filament\Resources\Suppliers\RelationManagers;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
        return ResourceCanAccessHelper::check('View Supplier Transactions');
    }

    public function table(Table $table): Table
    {
        return TransactionsTable::configure($table)
            ->headerActions([
                Action::make('pay')
                    ->label('Pay to Supplier')
                    ->visible(fn () => ResourceCanAccessHelper::check('Pay to Suppliers'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Pay to Suppliers'))
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('amount')
                                    ->label('Amount')
                                    ->numeric()
                                    ->required()
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
                        $supplier = $livewire->getOwnerRecord();
                        if (! array_key_exists('amount', $data) || ! array_key_exists('payment_method', $data)) {
                            Notification::make()
                                ->title('Missing payment details')
                                ->body('Please provide amount and payment method.')
                                ->danger()
                                ->send();

                            return;
                        }

                        app(PaymentService::class)->recordPayment(
                            payable: $supplier,
                            amount: $data['amount'],
                            paymentMethod: $data['payment_method'],
                            note: $data['note'] ?? null
                        );

                        Notification::make()
                            ->title('Supplier debited successfully')
                            ->success()
                            ->send();
                    })
                    ->icon(Heroicon::OutlinedArrowUp),
                Action::make('exportLedger')
                    ->label('Export Ledger')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn () => ResourceCanAccessHelper::check('Export Purchase Orders'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Export Purchase Orders'))
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
            ]);
    }

    public function downloadLedgerReport(string $format): StreamedResponse
    {
        $supplier = $this->getOwnerRecord();
        $store = Filament::getTenant();
        $timezone = $store?->timezone?->name ?? config('app.timezone', 'UTC');
        $decimalPlaces = $store?->currency?->decimal_places ?? 2;

        $metadataRows = [
            ['Store Name', $store?->business_name ?: $store?->name ?: '—'],
            ['Store Phone', $store?->phone ?: '—'],
            ['Store Email', $store?->email ?: '—'],
            ['Supplier Name', $supplier->name ?: '—'],
            ['Supplier Phone', $supplier->phone ?: '—'],
            ['Supplier Email', $supplier->email ?: '—'],
            ['Generated At', now()->setTimezone($timezone)->format('M d, Y g:i A')],
            [],
        ];

        $ledgerHeaderRow = ['Date', 'Reference', 'Note', 'Type', 'Amount', 'Balance'];
        $fileBaseName = Str::slug(($store?->name ?: 'store').'-'.($supplier->name ?: 'supplier').'-ledger-'.now()->format('Y-m-d-His'));

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
