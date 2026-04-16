<?php

it('hides brand and category status sections during creation while keeping them available for edits', function (): void {
    $brandForm = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Brands/Schemas/BrandForm.php');
    $categoryForm = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Categories/Schemas/CategoryForm.php');
    $attributeForm = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Attributes/Schemas/AttributeForm.php');
    $unitForm = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Units/Schemas/UnitForm.php');

    expect($brandForm)
        ->toContain("Section::make('Status')")
        ->toContain("->hidden(fn (?Model \$record): bool => \$record === null)")
        ->toContain("->columnSpan(fn (?Model \$record): int|string => \$record === null ? 'full' : 2)")
        ->toContain("Select::make('status')");

    expect($categoryForm)
        ->toContain("Section::make('Status')")
        ->toContain("->hidden(fn (?Model \$record): bool => \$record === null)")
        ->toContain("->columnSpan(fn (?Model \$record): int|string => \$record === null ? 'full' : 2)")
        ->toContain("Select::make('status')");

    expect($attributeForm)
        ->toContain('Grid::make(3)')
        ->toContain("Section::make('Attribute Detail')")
        ->toContain("TextInput::make('name')")
        ->toContain("->columnSpan('full')")
        ->toContain('->columnSpanFull()');

    expect($unitForm)
        ->toContain("Section::make('Unit Details')")
        ->toContain("Section::make('Conversion')")
        ->toContain('->columnSpanFull()');
});
