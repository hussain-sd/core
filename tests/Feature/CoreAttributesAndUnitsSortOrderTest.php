<?php

it('sorts attributes and units tables by latest id first', function (): void {
    $attributesTable = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Attributes/Tables/AttributesTable.php');
    $unitsTable = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Units/Tables/UnitsTable.php');

    expect($attributesTable)->toContain("->defaultSort('id', 'desc')");
    expect($unitsTable)->toContain("->defaultSort('id', 'desc')");
});
