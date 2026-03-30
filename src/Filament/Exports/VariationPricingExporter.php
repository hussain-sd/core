<?php

namespace SmartTill\Core\Filament\Exports;

use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Number;
use SmartTill\Core\Models\Variation;

class VariationPricingExporter extends BaseStoreExporter implements ShouldQueue
{
    protected static ?string $model = Variation::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('sku')
                ->label('SKU')
                ->formatStateUsing(function ($state) {
                    if ($state === null || $state === '') {
                        return '';
                    }

                    $escaped = str_replace('"', '""', (string) $state);

                    return '="'.$escaped.'"';
                }),
            ExportColumn::make('product.brand.name')
                ->label('Brand'),
            ExportColumn::make('product.category.name')
                ->label('Category'),
            ExportColumn::make('description')
                ->label('Description'),
            ExportColumn::make('price')
                ->label('Price'),
            ExportColumn::make('sale_price')
                ->label('Sale Price'),
            ExportColumn::make('sale_percentage')
                ->label('Sale %'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your variation pricing export has completed and '.Number::format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    protected function getExportTypeName(): string
    {
        return 'Variation-Pricing';
    }
}
