<?php

it('makes the shared sync reference column toggleable and hidden by default', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Helpers/SyncReferenceColumn.php');

    expect($contents)
        ->toContain("->toggleable(isToggledHiddenByDefault: true);");
});
