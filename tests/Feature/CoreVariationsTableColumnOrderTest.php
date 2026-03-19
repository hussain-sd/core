<?php

it('shows sku before description in the variations resource table', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Variations/Tables/VariationsTable.php');

    $skuPosition = strpos($contents, "TextColumn::make('sku')");
    $descriptionPosition = strpos($contents, "TextColumn::make('description')");

    expect($skuPosition)
        ->not->toBeFalse();

    expect($descriptionPosition)
        ->not->toBeFalse();

    expect($skuPosition)
        ->toBeLessThan($descriptionPosition);
});
