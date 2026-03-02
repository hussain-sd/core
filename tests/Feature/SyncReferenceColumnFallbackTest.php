<?php

it('configures sync reference column to fallback to local id before server id is synced', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Helpers/SyncReferenceColumn.php');

    expect($contents)
        ->toContain("TextColumn::make('reference')")
        ->toContain("->state(fn (\$record): ?string => filled(\$record->reference ?? null)")
        ->toContain(": (filled(\$record->server_id ?? null)")
        ->toContain(": (filled(\$record->local_id ?? null) ? (string) \$record->local_id : null)))")
        ->toContain("->description(fn (\$record): ?string => filled(\$record->server_id ?? null) && filled(\$record->local_id ?? null)");
});
