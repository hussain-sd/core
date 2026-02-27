<?php

it('registers morph map compatibility for host app model class names', function (): void {
    $contents = file_get_contents(__DIR__.'/../../src/Providers/CoreServiceProvider.php');

    expect($contents)
        ->toContain("'App\\Models\\Supplier' => Supplier::class")
        ->toContain("'App\\Models\\Customer' => Customer::class")
        ->toContain("'App\\Models\\Sale' => Sale::class");
});
