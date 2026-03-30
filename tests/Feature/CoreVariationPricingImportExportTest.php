<?php

it('defines a pricing-only variation exporter with pricing columns', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Exports/VariationPricingExporter.php');

    expect($contents)
        ->toContain('class VariationPricingExporter extends BaseStoreExporter implements ShouldQueue')
        ->toContain("ExportColumn::make('sku')")
        ->toContain("ExportColumn::make('product.brand.name')")
        ->toContain("ExportColumn::make('product.category.name')")
        ->toContain("ExportColumn::make('description')")
        ->toContain("ExportColumn::make('price')")
        ->toContain("ExportColumn::make('sale_price')")
        ->not->toContain("ExportColumn::make('sale_percentage')")
        ->not->toContain("ExportColumn::make('stock')")
        ->not->toContain('latestStock')
        ->toContain("return 'Variation-Pricing';");
});

it('defines a pricing importer that updates variation pricing fields only', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Imports/VariationPricingImporter.php');

    expect($contents)
        ->toContain('class VariationPricingImporter extends Importer')
        ->toContain("ImportColumn::make('sku')")
        ->toContain("ImportColumn::make('brand')")
        ->toContain("ImportColumn::make('category')")
        ->toContain("ImportColumn::make('description')")
        ->toContain("ImportColumn::make('price')")
        ->toContain("ImportColumn::make('sale_price')")
        ->not->toContain("ImportColumn::make('sale_percentage')")
        ->toContain('parent::fillRecord();')
        ->toContain("\$this->record->price = (float) (\$this->data['price'] ?? 0);")
        ->not->toContain("ImportColumn::make('stock')")
        ->not->toContain("ImportColumn::make('barcode')")
        ->not->toContain('Stock::create(');
});

it('relies on the variation observer to derive sale percentage from sale price', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Imports/VariationPricingImporter.php');

    expect($contents)
        ->toContain("\$record->sale_price = (float) \$state;")
        ->not->toContain("\$record->sale_percentage = round((float) \$state, 6);");
});

it('adds separate variation pricing import and export actions to the variations table', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Variations/Tables/VariationsTable.php');

    expect($contents)
        ->toContain('use SmartTill\\Core\\Filament\\Exports\\VariationPricingExporter;')
        ->toContain('use SmartTill\\Core\\Filament\\Imports\\VariationPricingImporter;')
        ->toContain("ImportAction::make('importVariationPricing')")
        ->toContain("->label('Import Pricing')")
        ->toContain('->importer(VariationPricingImporter::class)')
        ->toContain("ExportBulkAction::make('exportVariationPricing')")
        ->toContain("->label('Export Pricing')")
        ->toContain('->exporter(VariationPricingExporter::class)');
});
