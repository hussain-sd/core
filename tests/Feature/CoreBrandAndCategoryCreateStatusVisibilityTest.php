<?php

it('hides brand and category status sections during creation while keeping them available for edits', function (): void {
    $brandForm = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Brands/Schemas/BrandForm.php');
    $categoryForm = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Categories/Schemas/CategoryForm.php');

    expect($brandForm)
        ->toContain("Section::make('Status')")
        ->toContain("->hidden(fn (?Model \$record): bool => \$record === null)")
        ->toContain("Select::make('status')");

    expect($categoryForm)
        ->toContain("Section::make('Status')")
        ->toContain("->hidden(fn (?Model \$record): bool => \$record === null)")
        ->toContain("Select::make('status')");
});
