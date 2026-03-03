<?php

it('guards store scoped observer by config and supports created fallback', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Observers/StoreScopedReferenceObserver.php');

    expect($contents)
        ->toContain("config('smart_till.reference_on_create', true) !== true")
        ->toContain('public function created(Model $model): void')
        ->toContain('public function saved(Model $model): void')
        ->toContain("->whereNull('reference')")
        ->toContain("->max() + 1");
});
