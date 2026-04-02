<?php

namespace SmartTill\Core\Filament\Resources\Products\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use SmartTill\Core\Filament\Resources\Attributes\Schemas\AttributeForm;
use SmartTill\Core\Filament\Resources\Brands\Schemas\BrandForm;
use SmartTill\Core\Filament\Resources\Categories\Schemas\CategoryForm;
use SmartTill\Core\Models\Attribute;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        // Closure to (re)build variation entries from current state.
        $buildVariations = function (callable $get): array {
            $ttlHours = (int) config('products.variation_cache_ttl_hours', 12);
            $cacheUntil = now()->addHours($ttlHours);
            $storeId = Filament::getTenant()?->getKey();
            $attributeVersion = Cache::get('attribute_cache_version_'.($storeId ?? 'global'), 0);

            $name = trim((string) ($get('name') ?? ''));
            $base = $name; // code removed

            if (! $get('has_variations')) {
                return [
                    'single' => [
                        'key' => 'single',
                        'description' => $base ?: 'Untitled Product',
                    ],
                ];
            }

            $attributesState = $get('attributes') ?? [];
            if (empty($attributesState)) {
                return [];
            }

            // Fetch attribute names in bulk to avoid per-row queries.
            $attributeIds = collect($attributesState)
                ->pluck('attribute_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $attributeNamesById = [];
            if (! empty($attributeIds)) {
                $cacheKey = 'product_form_attrs_'.($storeId ?? 'global').'_'.$attributeVersion.'_'.md5(json_encode($attributeIds));
                $attributeNamesById = Cache::remember($cacheKey, $cacheUntil, function () use ($attributeIds, $storeId) {
                    $query = Attribute::query()->whereIn('id', $attributeIds);
                    if ($storeId) {
                        $query->where('store_id', $storeId);
                    }

                    return $query->pluck('name', 'id')->toArray();
                });
            }

            $attrValueSets = [];
            $attrOrder = [];
            foreach ($attributesState as $attrBlock) {
                $attributeId = $attrBlock['attribute_id'] ?? null;
                if (! $attributeId) {
                    continue;
                }
                $attributeName = $attributeNamesById[$attributeId] ?? null;
                if (! $attributeName) {
                    continue;
                }
                $values = static::normalizeAttributeValues($attrBlock['values'] ?? []);
                if (empty($values)) {
                    return [];
                }
                $attrOrder[] = $attributeName;

                // Build two-part representations for each value: keyPart (raw value only) and displayPart.
                $valuePairs = [];
                foreach ($values as $rawVal) {
                    $rawVal = trim((string) $rawVal);

                    // Display string may include unit parts, but key must NOT.
                    $display = $rawVal;

                    $valuePairs[] = [
                        'k' => $rawVal, // key part only uses attribute value text
                        'd' => $display, // display part for UI
                    ];
                }
                if (empty($valuePairs)) {
                    return [];
                }
                $attrValueSets[$attributeName] = $valuePairs;
            }
            if (empty($attrValueSets)) {
                return [];
            }

            // Cartesian product
            $combinations = [[]];
            foreach ($attrValueSets as $attrName => $pairs) {
                $next = [];
                foreach ($combinations as $partial) {
                    foreach ($pairs as $pair) { // pair = ['k' => rawVal, 'd' => display]
                        $p = $partial;
                        $p[$attrName] = $pair;
                        $next[] = $p;
                    }
                }
                $combinations = $next;
            }

            $result = [];
            foreach ($combinations as $combo) {
                $keySegments = [];
                $descParts = [];
                foreach ($attrOrder as $attrName) {
                    if (isset($combo[$attrName])) {
                        $keySegments[] = $attrName.'='.$combo[$attrName]['k']; // key uses raw value only
                        $descParts[] = $attrName.': '.$combo[$attrName]['d']; // description uses display
                    }
                }
                $key = implode('|', $keySegments);
                $descLabel = implode(', ', $descParts);
                $description = $base !== '' ? ($base.' - '.$descLabel) : $descLabel;
                $result[$key] = [
                    'key' => $key,
                    'description' => $description,
                ];
            }

            return $result;
        };

        // Build metadata and signature factory to avoid duplication and per-row Attribute lookups.
        $buildAttrMeta = function (callable $get): array {
            $attributesState = $get('attributes') ?? [];
            $attributeIds = collect($attributesState)
                ->pluck('attribute_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $attributeNamesById = empty($attributeIds)
                ? []
                : Attribute::query()->whereIn('id', $attributeIds)->pluck('name', 'id')->toArray();

            $orderedNames = [];
            $rawValuesByName = [];
            foreach ($attributesState as $attrBlock) {
                $attributeId = $attrBlock['attribute_id'] ?? null;
                if (! $attributeId) {
                    continue;
                }
                $attributeName = $attributeNamesById[$attributeId] ?? null;
                if (! $attributeName) {
                    continue;
                }
                $orderedNames[] = $attributeName;
                $rawValuesByName[$attributeName] = static::normalizeAttributeValues($attrBlock['values'] ?? []);
            }

            return [$orderedNames, $rawValuesByName];
        };

        $makeSignatureFromAttrPartFactory = function (callable $get) use ($buildAttrMeta): callable {
            [$orderedNames, $rawValuesByName] = (function (callable $g) use ($buildAttrMeta) {
                return $buildAttrMeta($g);
            })($get);

            return function (string $attrPart) use ($orderedNames, $rawValuesByName): ?string {
                if ($attrPart === '') {
                    return null;
                }
                // Build a map of segments: "Attribute: value..."
                $segments = [];
                foreach (explode(', ', $attrPart) as $seg) {
                    $pieces = explode(': ', $seg, 2);
                    if (count($pieces) !== 2) {
                        continue;
                    }
                    [$attrName, $valueStr] = $pieces;
                    $segments[$attrName] = $valueStr;
                }
                $parts = [];
                foreach ($orderedNames as $name) {
                    if (! array_key_exists($name, $segments)) {
                        continue;
                    }
                    $valueStr = $segments[$name];
                    $candidates = $rawValuesByName[$name] ?? [];
                    $best = null;
                    $bestLen = -1;
                    foreach ($candidates as $cand) {
                        if ($cand !== '' && str_starts_with($valueStr, $cand) && strlen($cand) > $bestLen) {
                            $best = $cand;
                            $bestLen = strlen($cand);
                        }
                    }
                    $raw = $best ?? trim($valueStr);
                    $parts[] = $name.'='.$raw;
                }

                return empty($parts) ? null : implode('|', $parts);
            };
        };

        /**
         * Generate variations on-demand (no background recalculation).
         */
        $generateVariations = function (callable $get, callable $set) use ($buildVariations, $makeSignatureFromAttrPartFactory): void {
            $set('variations_loading', true);
            $maxCombinations = (int) config('filament_products.max_variation_combinations', 1000);

            // Calculate combination count without building rows
            $attributesState = $get('attributes') ?? [];
            $combinationCount = 1;
            foreach ($attributesState as $attrBlock) {
                $valueCount = count(static::normalizeAttributeValues($attrBlock['values'] ?? []));
                if ($valueCount === 0) {
                    $combinationCount = 0;
                    break;
                }
                $combinationCount *= $valueCount;
                if ($combinationCount > $maxCombinations) {
                    break;
                }
            }

            if ($combinationCount > $maxCombinations) {
                $set('variations_warning', "Too many combinations ({$combinationCount}). Please reduce attributes/values. Limit: {$maxCombinations}.");
                $set('variations', []);
                $set('variations_generated', false);
                $set('variations_ready', false);
                $set('variations_loading', false);

                return;
            }

            $set('variations_warning', null);
            $fresh = $buildVariations($get); // keyed by attribute combination
            $existing = collect($get('variations') ?? []);

            // Assign keys for legacy rows if missing.
            if ($existing->isNotEmpty() && $existing->contains(fn ($r) => empty($r['key']))) {
                $freshBySignature = collect($fresh);
                $makeSignatureFromAttrPart = $makeSignatureFromAttrPartFactory($get);

                $existing = $existing->map(function ($row) use ($makeSignatureFromAttrPart, $freshBySignature) {
                    if (! empty($row['key'])) {
                        return $row;
                    }

                    $desc = $row['description'] ?? '';
                    $pos = strrpos($desc, ' - ');
                    $attrPart = $pos === false ? $desc : substr($desc, $pos + 3);
                    $signature = $makeSignatureFromAttrPart($attrPart);
                    if ($signature && isset($freshBySignature[$signature])) {
                        $row['key'] = $signature;
                        $row['description'] = $freshBySignature[$signature]['description'];
                    }

                    return $row;
                });
            }

            if (empty($fresh)) {
                $set('variations', []);
                $set('variations_generated', true);
                $set('variations_ready', true);
                $set('variations_loading', false);

                return;
            }

            $existingByKey = $existing->keyBy('key');
            $merged = [];

            foreach ($fresh as $k => $row) {
                $prev = $existingByKey[$k] ?? [];
                $merged[] = array_merge($row, [
                    'id' => $prev['id'] ?? null,
                    'sku' => $prev['sku'] ?? null,
                    'price' => $prev['price'] ?? null,
                    'sale_price' => $prev['sale_price'] ?? null,
                    'sale_percentage' => $prev['sale_percentage'] ?? null,
                    'tax_percentage' => $prev['tax_percentage'] ?? null,
                    'tax_amount' => $prev['tax_amount'] ?? null,
                    'supplier_percentage' => $prev['supplier_percentage'] ?? null,
                    'supplier_price' => $prev['supplier_price'] ?? null,
                    'unit_id' => $prev['unit_id'] ?? null,
                ]);
            }

            // Append orphaned existing rows that no longer map to combinations only if they have ids (keep for review)
            foreach ($existing as $row) {
                if (! empty($row['id']) && empty($row['key'])) {
                    $merged[] = $row;
                }
            }

            $set('variations', $merged);
            $set('variations_generated', true);
            $set('variations_ready', true);
            $set('variations_loading', false);
        };

        // DRY: shared save logic for both single & multi variation repeaters.
        $saveVariations = function (Repeater $component, $livewire, ?array $state): void {
            // Upsert variations and delete records that are no longer present in the form state.
            $relationship = $component->getRelationship();
            $query = $relationship->getQuery();
            $keyName = $relationship->getRelated()->getKeyName();

            $existing = $query->get()->keyBy($keyName);
            $state = is_array($state) ? $state : [];

            // Determine which existing IDs are still present in the submitted state.
            $stateIds = collect($state)
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();

            // Delete any records that are no longer represented in the state.
            $idsToDelete = $existing->keys()->diff($stateIds);
            if ($idsToDelete->isNotEmpty()) {
                $query->whereIn($keyName, $idsToDelete)->get()->each(fn ($model) => $model->delete());
                foreach ($idsToDelete as $delId) {
                    $existing->forget($delId);
                }
            }

            // Upsert current items.
            foreach ($state as $item) {
                $id = $item['id'] ?? null;
                $data = [
                    'description' => $item['description'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'price' => $item['price'] ?? null,
                    'sale_price' => $item['sale_price'] ?? null,
                    'sale_percentage' => $item['sale_percentage'] ?? null,
                    'tax_percentage' => $item['tax_percentage'] ?? null,
                    'tax_amount' => $item['tax_amount'] ?? null,
                    'supplier_percentage' => $item['supplier_percentage'] ?? null,
                    'supplier_price' => $item['supplier_price'] ?? null,
                    'unit_id' => $item['unit_id'] ?? null,
                ];

                if ($id && isset($existing[$id])) {
                    $variation = $existing[$id]->fill($data);
                    $variation->save();
                } else {
                    $variation = $relationship->create($data);
                }

            }
        };

        // Reusable UI definitions for variations table and fields.
        $variationTableColumns = [
            Repeater\TableColumn::make('Description')->width('50%'),
            Repeater\TableColumn::make('SKU')->width('15%'),
            Repeater\TableColumn::make('Price')->width('10%'),
            Repeater\TableColumn::make('Sale Price')->width('10%'),
            Repeater\TableColumn::make('Unit')->width('15%'),
        ];
        $variationFields = [
            Hidden::make('id'),
            Hidden::make('key')->dehydrated(false),
            TextInput::make('description')
                ->label('Product Description')
                ->disabled()
                ->extraInputAttributes([
                    'class' => 'text-xs py-0.5 px-1.5 h-7',
                    'data-sale-item-input' => 'true',
                    'x-on:focus' => '$event.target.select && $event.target.select()',
                ])
                ->dehydrated(),
            Hidden::make('variations_warning')->dehydrated(false),
            Hidden::make('variations_loading')->default(false)->dehydrated(false),
            Hidden::make('variations_generated')->default(false)->dehydrated(false),
            TextInput::make('sku')
                ->label('SKU')
                ->nullable()
                ->extraInputAttributes([
                    'class' => 'text-xs py-0.5 px-1.5 h-7',
                    'data-sale-item-input' => 'true',
                    'x-on:focus' => '$event.target.select && $event.target.select()',
                ])
                ->placeholder('SKU123'),
            TextInput::make('price')
                ->label('Regular Price')
                ->nullable()
                ->placeholder('100.00')
                ->live(onBlur: true)
                ->extraInputAttributes([
                    'class' => 'text-xs py-0.5 px-1.5 h-7',
                    'data-sale-item-input' => 'true',
                    'x-on:focus' => '$event.target.select && $event.target.select()',
                ])
                ->afterStateUpdated(function ($state, $set, $get, $livewire) {
                    $store = Filament::getTenant();
                    $currency = $store?->currency;
                    $decimalPlaces = $currency->decimal_places ?? 2;

                    // Round to correct decimal places
                    if (is_numeric($state)) {
                        $rounded = round((float) $state, $decimalPlaces);
                        if ((float) $state !== $rounded) {
                            $set('price', $rounded);
                        }
                    }

                    $salePrice = $get('sale_price');

                    if (! $salePrice) {
                        $set('sale_price', $state);
                    } elseif ($salePrice > $state) {
                        $set('sale_price', $state);
                    }
                }),
            TextInput::make('sale_price')
                ->label('Sale Price')
                ->nullable()
                ->placeholder('100.00')
                ->live(onBlur: true)
                ->extraInputAttributes([
                    'class' => 'text-xs py-0.5 px-1.5 h-7',
                    'data-sale-item-input' => 'true',
                    'x-on:focus' => '$event.target.select && $event.target.select()',
                ])
                ->afterStateUpdated(function ($state, $set, $get, $livewire) {
                    $store = Filament::getTenant();
                    $currency = $store?->currency;
                    $decimalPlaces = $currency->decimal_places ?? 2;

                    // Round to correct decimal places
                    if (is_numeric($state)) {
                        $rounded = round((float) $state, $decimalPlaces);
                        if ((float) $state !== $rounded) {
                            $set('sale_price', $rounded);
                        }
                    }
                }),
            Select::make('unit_id')
                ->hiddenLabel()
                ->relationship(
                    name: 'unit',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query) => $query->forStoreOrGlobal(Filament::getTenant()?->getKey()),
                )
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->symbol ? "{$record->name} ({$record->symbol})" : $record->name)
                ->extraInputAttributes([
                    'class' => 'text-xs py-0.5 px-1.5 h-7',
                    'data-sale-item-input' => 'true',
                ])
                ->placeholder('Select Unit'),
        ];

        return $schema
            ->components([
                Hidden::make('variations_generated')
                    ->default(false)
                    ->dehydrated(false),
                Hidden::make('variations_loading')
                    ->default(false)
                    ->dehydrated(false),
                Checkbox::make('variations_ready')
                    ->label('Variations generated')
                    ->default(true)
                    ->visible(false)
                    ->dehydrated(true)
                    ->rule('accepted_if:has_variations,1'),

                Section::make('Product Classification')
                    ->description('Organize your product with brand and category')
                    ->schema([
                        Select::make('brand_id')
                            ->label('Brand')
                            ->relationship('brand', 'name')
                            ->createOptionForm(fn (Schema $schema) => BrandForm::configure($schema))
                            ->searchable()
                            ->preload()
                            ->placeholder('Select or create a brand')
                            ->helperText('Choose the manufacturer or brand'),
                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->createOptionForm(fn (Schema $schema) => CategoryForm::configure($schema))
                            ->searchable()
                            ->preload()
                            ->placeholder('Select or create a category')
                            ->helperText('Choose the product category'),
                    ])
                    ->columns()
                    ->columnSpanFull(),

                Section::make('Product Details')
                    ->description('Essential product information')
                    ->schema([
                        TextInput::make('name')
                            ->label('Product Name')
                            ->required()
                            ->live(onBlur: true)
                            ->placeholder('Enter product name')
                            ->helperText('Clear, descriptive product title')
                            ->maxLength(255)
                            ->afterStateUpdated(function ($state, callable $set, callable $get) use ($buildVariations, $makeSignatureFromAttrPartFactory) {
                                if ($get('has_variations')) {
                                    $fresh = collect($buildVariations($get));
                                    $existing = collect($get('variations') ?? []);

                                    if ($existing->isEmpty()) {
                                        $set('variations', array_values($fresh->all()));
                                        $set('variations_generated', true);
                                        $set('variations_ready', true);

                                        return;
                                    }

                                    if ($existing->contains(fn ($row) => empty($row['key']))) {
                                        $makeSignatureFromAttrPart = $makeSignatureFromAttrPartFactory($get);

                                        $existing = $existing->map(function ($row) use ($makeSignatureFromAttrPart, $fresh) {
                                            if (! empty($row['key'])) {
                                                return $row;
                                            }

                                            $description = (string) ($row['description'] ?? '');
                                            $position = strrpos($description, ' - ');
                                            $attributePart = $position === false ? $description : substr($description, $position + 3);
                                            $signature = $makeSignatureFromAttrPart($attributePart);

                                            if ($signature && isset($fresh[$signature])) {
                                                $row['key'] = $signature;
                                            }

                                            return $row;
                                        });
                                    }

                                    $updated = $existing->map(function ($row) use ($fresh) {
                                        $key = $row['key'] ?? null;
                                        if ($key && isset($fresh[$key]['description'])) {
                                            $row['description'] = $fresh[$key]['description'];
                                        }

                                        return $row;
                                    });

                                    $set('variations', $updated->values()->all());
                                    $set('variations_generated', true);
                                    $set('variations_ready', true);

                                    return;
                                }

                                // In single-product mode, keep existing rows but refresh description instantly.
                                $existing = collect($get('variations') ?? []);
                                if ($existing->isNotEmpty()) {
                                    $updated = $existing->map(function ($row) use ($state) {
                                        $row['description'] = $state ?: ($row['description'] ?? '');

                                        return $row;
                                    });
                                    $set('variations', $updated->values()->all());
                                    $set('variations_generated', true);
                                    $set('variations_ready', true);

                                    return;
                                }

                                $fresh = $buildVariations($get);
                                $set('variations', array_values($fresh));
                                $set('variations_generated', true);
                                $set('variations_ready', true);
                            }),
                        TextInput::make('description')
                            ->label('Product Description')
                            ->nullable()
                            ->placeholder('Describe product features and benefits')
                            ->helperText('Detailed product information for customers')
                            ->columnSpanFull(),
                        Toggle::make('is_preparable')
                            ->label('Is Preparable Product')
                            ->helperText('Enable if this product can be prepared from other products (e.g., a meal made from ingredients)')
                            ->default(false)
                            ->columnSpanFull(),
                    ])
                    ->columns()
                    ->columnSpanFull(),

                Section::make('Product Images')
                    ->description('Upload one or more images for this product')
                    ->schema([
                        FileUpload::make('image_paths')
                            ->label('Images')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->panelLayout('grid')
                            ->itemPanelAspectRatio('1:1')
                            ->imagePreviewHeight('160')
                            ->uploadButtonPosition('right bottom')
                            ->removeUploadedFileButtonPosition('left bottom')
                            ->maxParallelUploads(2)
                            ->helperText('Upload multiple images, drag to reorder, and click a thumbnail to preview.')
                            ->extraAttributes(['class' => 'fi-upload-grid-5'])
                            ->getUploadedFileUsing(function (FileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                                $disk = $component->getDisk();

                                if (! $disk->exists($file)) {
                                    return null;
                                }

                                return [
                                    'name' => Arr::get(Arr::wrap($storedFileNames), $file, basename($file)),
                                    'size' => $disk->size($file),
                                    'type' => $disk->mimeType($file),
                                    'url' => '/storage/'.ltrim($file, '/'),
                                ];
                            })
                            ->disk('public')
                            ->directory('products/images')
                            ->visibility('public')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Variations & Attributes')
                    ->schema([
                        Toggle::make('has_variations')
                            ->label('Enable Product Variations')
                            ->helperText('Enable if your product comes in different sizes, colors, or other variations')
                            ->default(false)
                            ->live(false)
                            ->afterStateUpdated(function ($state, callable $set, callable $get) use ($buildVariations, $generateVariations) {
                                if (! $state) {
                                    // Single-product mode: rebuild a single variation from name.
                                    $fresh = $buildVariations($get);
                                    $set('variations', array_values($fresh));
                                    $set('variations_ready', true);
                                    $set('variations_generated', true);

                                    return;
                                } else {
                                    // Multi-variation mode: clear list and require generation.
                                    $set('variations', []);
                                    $set('variations_ready', false);
                                    $set('variations_generated', false);

                                    if (! empty($get('attributes'))) {
                                        $generateVariations($get, $set);
                                    }
                                }
                            }),
                        Text::make('attributes_help')
                            ->content('Add attributes like Size, Color, or Material. Each attribute can have multiple values that will automatically generate product variations.')
                            ->visible(fn ($get) => $get('has_variations'))
                            ->columnSpanFull(),
                        Repeater::make('attributes')
                            ->label('Attributes')
                            ->relationship()
                            ->addActionLabel('Add Attribute')
                            ->visible(fn ($get) => $get('has_variations'))
                            ->schema([
                                Select::make('attribute_id')
                                    ->label('Attribute Type')
                                    ->relationship(
                                        name: 'attribute',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: function ($query, $context, $get) {
                                            $current = (int) ($get('../attribute_id') ?? 0);
                                            $selected = collect($get('../../attributes') ?? [])
                                                ->pluck('attribute_id')
                                                ->filter()
                                                ->map(fn ($id) => (int) $id)
                                                ->unique()
                                                ->values()
                                                ->all();
                                            $exclude = array_values(array_diff($selected, [$current]));
                                            if (! empty($exclude)) {
                                                $query->whereNotIn('id', $exclude);
                                            }

                                            return $query;
                                        }
                                    )
                                    ->live()
                                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                                    ->getOptionLabelUsing(fn ($value) => Attribute::query()->find($value)?->name)
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->placeholder('Select attribute type')
                                    ->helperText('Choose the type of variation (e.g., Size, Color)')
                                    ->createOptionForm(fn (Schema $schema) => AttributeForm::configure($schema))
                                    ->afterStateUpdated(function (callable $get, callable $set) use ($generateVariations): void {
                                        if ($get('../../has_variations')) {
                                            $generateVariations($get, $set);
                                        }
                                    }),
                                TagsInput::make('values')
                                    ->label('Attribute Values')
                                    ->placeholder('Type a value and press Enter')
                                    ->helperText('Press Enter after each value (e.g., Small, Medium, Large)')
                                    ->live()
                                    ->visible(fn ($get) => filled($get('attribute_id')))
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) use ($generateVariations): void {
                                        if ($get('../../has_variations')) {
                                            $generateVariations($get, $set);
                                        }
                                    }),
                            ])
                            ->columnSpanFull()
                            ->afterStateUpdated(function (callable $get, callable $set) use ($generateVariations): void {
                                if ($get('has_variations')) {
                                    $generateVariations($get, $set);
                                }
                            }),
                    ])
                    ->columnSpanFull(),

                Section::make('Variations')
                    ->description('Configure pricing and inventory for your product or variations')
                    ->visible(fn ($get) => ! $get('has_variations') || $get('variations_ready'))
                    ->schema([
                        Text::make('variations_warning_text')
                            ->content(fn ($get) => $get('variations_warning'))
                            ->color('danger')
                            ->visible(fn ($get) => filled($get('variations_warning')))
                            ->columnSpanFull(),
                        Text::make('variations_loading_text')
                            ->content('Generating variations...')
                            ->color('info')
                            ->visible(fn ($get) => $get('variations_loading') === true)
                            ->columnSpanFull(),
                        Text::make('variations_help')
                            ->content('Configure pricing and SKU for your product or each variation.')
                            ->visible(fn ($get) => (! $get('has_variations')) || $get('variations_ready'))
                            ->columnSpanFull(),
                        Repeater::make('variations')
                            ->relationship('variations')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->saveRelationshipsUsing($saveVariations)
                            ->table($variationTableColumns)
                            ->schema($variationFields)
                            ->visible(fn ($get) => (! $get('has_variations')) || $get('variations_ready'))
                            ->afterStateHydrated(function (callable $get, callable $set, $state) use ($buildVariations) {
                                $existing = collect($state ?? []);

                                if ($get('has_variations')) {
                                    $set('variations', $existing->values()->all());
                                    $set('variations_ready', true);

                                    return;
                                }

                                // Single-product mode: if existing rows, keep as-is.
                                if ($existing->isNotEmpty()) {
                                    $set('variations', $existing->values()->all());
                                    $set('variations_ready', true);

                                    return;
                                }

                                // No existing rows: build from name
                                $fresh = $buildVariations($get);
                                $set('variations', array_values($fresh));
                                $set('variations_ready', true);
                            }),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function normalizeAttributeValues(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => is_array($value) ? ($value['value'] ?? null) : $value)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
