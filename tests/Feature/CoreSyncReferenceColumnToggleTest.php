<?php

it('makes the shared sync reference column toggleable and hidden by default', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Helpers/SyncReferenceColumn.php');

    expect($contents)
        ->toContain('public static function make(bool $isToggledHiddenByDefault = true): TextColumn')
        ->toContain("->toggleable(isToggledHiddenByDefault: \$isToggledHiddenByDefault);");
});

it('keeps the sales reference column visible by default', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Sales/Tables/SalesTable.php');

    expect($contents)
        ->toContain('SyncReferenceColumn::make(isToggledHiddenByDefault: false),');
});
