<?php

it('refreshes variation descriptions in the product form when the product name changes', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/Products/Schemas/ProductForm.php');

    expect($contents)
        ->toContain("TextInput::make('name')")
        ->toContain("if (\$get('has_variations')) {")
        ->toContain("\$fresh = collect(\$buildVariations(\$get));")
        ->toContain("if (\$existing->contains(fn (\$row) => empty(\$row['key']))) {")
        ->toContain("\$row['key'] = \$signature;")
        ->toContain("\$row['description'] = \$fresh[\$key]['description'];")
        ->toContain("\$set('variations_generated', true);")
        ->toContain("\$set('variations_ready', true);");
});
