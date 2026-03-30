<?php

namespace SmartTill\Core\Filament\Imports;

use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use SmartTill\Core\Models\Variation;

class VariationPricingImporter extends Importer
{
    protected static ?string $model = Variation::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku')
                ->label('SKU')
                ->exampleHeader('SKU')
                ->requiredMapping()
                ->rules(['required', 'string'])
                ->fillRecordUsing(fn (): null => null),
            ImportColumn::make('brand')
                ->label('Brand')
                ->exampleHeader('Brand')
                ->requiredMapping()
                ->rules(['required', 'string'])
                ->fillRecordUsing(fn (): null => null),
            ImportColumn::make('category')
                ->label('Category')
                ->exampleHeader('Category')
                ->requiredMapping()
                ->rules(['required', 'string'])
                ->fillRecordUsing(fn (): null => null),
            ImportColumn::make('description')
                ->label('Description')
                ->exampleHeader('Description')
                ->requiredMapping()
                ->rules(['required', 'string'])
                ->fillRecordUsing(fn (): null => null),
            ImportColumn::make('price')
                ->label('Price')
                ->exampleHeader('Price')
                ->numeric()
                ->requiredMapping()
                ->rules(['required', 'numeric', 'min:0']),
            ImportColumn::make('sale_price')
                ->label('Sale Price')
                ->exampleHeader('Sale Price')
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0'])
                ->fillRecordUsing(function (Variation $record, $state): void {
                    if ($state === null || $state === '') {
                        $record->sale_price = null;

                        return;
                    }

                    $record->sale_price = (float) $state;
                }),
        ];
    }

    public function resolveRecord(): ?Variation
    {
        $storeId = $this->options['store_id'] ?? null;

        if (blank($storeId)) {
            throw ValidationException::withMessages([
                'price' => 'Cannot resolve store for this import job. Please start the import from within a store context.',
            ]);
        }

        $sku = trim((string) ($this->data['sku'] ?? ''));
        $brand = trim((string) ($this->data['brand'] ?? ''));
        $category = trim((string) ($this->data['category'] ?? ''));
        $description = trim((string) ($this->data['description'] ?? ''));

        if ($sku === '') {
            throw ValidationException::withMessages([
                'sku' => 'SKU is required.',
            ]);
        }

        $variations = Variation::query()
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->get();

        if ($variations->isEmpty()) {
            throw ValidationException::withMessages([
                'sku' => 'No variation found for this SKU in the current store.',
            ]);
        }

        $variations = $variations
            ->filter(fn (Variation $variation): bool => trim((string) $variation->description) === $description)
            ->filter(fn (Variation $variation): bool => trim((string) $variation->product?->brand?->name) === $brand)
            ->values();

        if ($variations->count() > 1) {
            throw ValidationException::withMessages([
                'sku' => 'Multiple variations matched this SKU, brand, and description. Please make the row more specific.',
            ]);
        }

        if ($variations->isEmpty()) {
            throw ValidationException::withMessages([
                'sku' => 'No variation matched this pricing row. Please verify SKU, brand, and description.',
            ]);
        }

        return $variations->sole();
    }

    public function fillRecord(): void
    {
        parent::fillRecord();

        $this->record->price = (float) ($this->data['price'] ?? 0);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your variation pricing import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
