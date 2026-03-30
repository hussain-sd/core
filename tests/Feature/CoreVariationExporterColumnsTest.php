<?php

it('exports the variation stock import required columns', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Exports/VariationExporter.php');

    expect($contents)
        ->toContain("ExportColumn::make('sku')")
        ->toContain("ExportColumn::make('product.brand.name')")
        ->toContain("ExportColumn::make('product.category.name')")
        ->toContain("ExportColumn::make('description')")
        ->toContain("ExportColumn::make('price')")
        ->toContain("ExportColumn::make('stock')")
        ->toContain("ExportColumn::make('latestStock.supplier_price')")
        ->toContain("ExportColumn::make('latestStock.tax_amount')")
        ->toContain("ExportColumn::make('latestStock.barcode')");
});

it('uses the latest stock relation for export-only stock fields', function (): void {
    $variationContents = file_get_contents(dirname(__DIR__, 2).'/src/Models/Variation.php');
    $exporterContents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Exports/VariationExporter.php');

    expect($variationContents)
        ->toContain('use Illuminate\\Database\\Eloquent\\Relations\\HasOne;')
        ->toContain('public function latestStock(): HasOne')
        ->toContain('return $this->hasOne(Stock::class)->latestOfMany();');

    expect($exporterContents)
        ->toContain("ExportColumn::make('latestStock.supplier_price')")
        ->toContain("ExportColumn::make('latestStock.tax_amount')")
        ->toContain("ExportColumn::make('latestStock.barcode')");
});
