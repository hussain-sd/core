<?php

it('syncs product-name changes into variation descriptions and uses wasChanged checks in the product observer', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2).'/src/Observers/ProductObserver.php');

    expect($contents)
        ->toContain("if (\$product->wasChanged('brand_id'))")
        ->toContain("if (\$product->wasChanged('name'))")
        ->toContain("\$originalName = trim((string) \$product->getOriginal('name'));")
        ->toContain("if (! \$product->has_variations)")
        ->toContain("\$prefix = \$originalName.' - ';")
        ->toContain("'description' => \$currentName.substr(\$description, strlen(\$originalName))");
});
