<?php

it('does not enforce default grouping in units table so latest records are visible immediately', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Units/Tables/UnitsTable.php');

    expect($contents)
        ->toContain("->groups([")
        ->toContain("->defaultSort('id', 'desc')")
        ->not->toContain("->defaultGroup('dimension.name')");
});
