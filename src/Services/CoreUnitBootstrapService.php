<?php

namespace SmartTill\Core\Services;

use SmartTill\Core\Models\Unit;
use SmartTill\Core\Models\UnitDimension;

class CoreUnitBootstrapService
{
    public function ensureUnitData(?string $connection = null): array
    {
        $stats = [
            'dimensions_created' => 0,
            'units_created' => 0,
            'units_updated' => 0,
        ];

        foreach ($this->definitions() as $definition) {
            $dimension = $this->upsertDimension($definition, $connection, $stats);
            $baseUnit = $this->upsertUnit($dimension->id, $definition['base'], $connection, $stats);

            foreach ($definition['units'] as $unitDefinition) {
                $this->upsertUnit($dimension->id, $unitDefinition, $connection, $stats);
            }

            if ((int) $dimension->base_unit_id !== (int) $baseUnit->id) {
                $dimension->base_unit_id = $baseUnit->id;
                $dimension->save();
            }
        }

        return $stats;
    }

    private function upsertDimension(array $definition, ?string $connection, array &$stats): UnitDimension
    {
        $query = $connection ? UnitDimension::on($connection) : UnitDimension::query();
        $dimension = $query->firstOrCreate([
            'name' => $definition['name'],
        ]);

        if ($dimension->wasRecentlyCreated) {
            $stats['dimensions_created']++;
        }

        return $dimension;
    }

    private function upsertUnit(int $dimensionId, array $definition, ?string $connection, array &$stats): Unit
    {
        $query = $connection ? Unit::on($connection) : Unit::query();

        $unit = $query
            ->withTrashed()
            ->whereNull('store_id')
            ->where('dimension_id', $dimensionId)
            ->where('name', $definition['name'])
            ->first();

        if (! $unit) {
            $unit = new Unit;

            if ($connection) {
                $unit->setConnection($connection);
            }

            $unit->store_id = null;
            $unit->dimension_id = $dimensionId;
            $unit->name = $definition['name'];
        }

        $unit->symbol = $definition['symbol'] ?? null;
        $unit->code = $definition['code'] ?? null;
        $unit->to_base_factor = $definition['to_base_factor'];
        $unit->to_base_offset = $definition['to_base_offset'] ?? 0;
        $unit->deleted_at = null;

        if (! $unit->exists) {
            $unit->save();
            $stats['units_created']++;

            return $unit;
        }

        if ($unit->isDirty()) {
            $unit->save();
            $stats['units_updated']++;
        }

        return $unit;
    }

    private function definitions(): array
    {
        return [
            [
                'name' => 'Mass',
                'base' => [
                    'name' => 'Gram',
                    'symbol' => 'g',
                    'code' => 'g',
                    'to_base_factor' => 1,
                    'to_base_offset' => 0,
                ],
                'units' => [
                    ['name' => 'Milligram', 'symbol' => 'mg', 'code' => 'mg', 'to_base_factor' => 0.001, 'to_base_offset' => 0],
                    ['name' => 'Kilogram', 'symbol' => 'kg', 'code' => 'kg', 'to_base_factor' => 1000, 'to_base_offset' => 0],
                    ['name' => 'Tonne', 'symbol' => 't', 'code' => 't', 'to_base_factor' => 1000000, 'to_base_offset' => 0],
                    ['name' => 'Ounce', 'symbol' => 'oz', 'code' => 'oz', 'to_base_factor' => 28.349523125, 'to_base_offset' => 0],
                    ['name' => 'Pound', 'symbol' => 'lb', 'code' => 'lb', 'to_base_factor' => 453.59237, 'to_base_offset' => 0],
                ],
            ],
            [
                'name' => 'Volume',
                'base' => [
                    'name' => 'Milliliter',
                    'symbol' => 'ml',
                    'code' => 'ml',
                    'to_base_factor' => 1,
                    'to_base_offset' => 0,
                ],
                'units' => [
                    ['name' => 'Liter', 'symbol' => 'L', 'code' => 'l', 'to_base_factor' => 1000, 'to_base_offset' => 0],
                    ['name' => 'Teaspoon', 'symbol' => 'tsp', 'code' => 'tsp', 'to_base_factor' => 4.92892159375, 'to_base_offset' => 0],
                    ['name' => 'Tablespoon', 'symbol' => 'tbsp', 'code' => 'tbsp', 'to_base_factor' => 14.78676478125, 'to_base_offset' => 0],
                    ['name' => 'Cup', 'symbol' => 'cup', 'code' => 'cup', 'to_base_factor' => 240, 'to_base_offset' => 0],
                    ['name' => 'Fluid Ounce', 'symbol' => 'fl oz', 'code' => 'floz', 'to_base_factor' => 29.5735295625, 'to_base_offset' => 0],
                    ['name' => 'Pint', 'symbol' => 'pt', 'code' => 'pt', 'to_base_factor' => 473.176473, 'to_base_offset' => 0],
                    ['name' => 'Quart', 'symbol' => 'qt', 'code' => 'qt', 'to_base_factor' => 946.352946, 'to_base_offset' => 0],
                    ['name' => 'Gallon', 'symbol' => 'gal', 'code' => 'gal', 'to_base_factor' => 3785.411784, 'to_base_offset' => 0],
                ],
            ],
            [
                'name' => 'Length',
                'base' => [
                    'name' => 'Millimeter',
                    'symbol' => 'mm',
                    'code' => 'mm',
                    'to_base_factor' => 1,
                    'to_base_offset' => 0,
                ],
                'units' => [
                    ['name' => 'Centimeter', 'symbol' => 'cm', 'code' => 'cm', 'to_base_factor' => 10, 'to_base_offset' => 0],
                    ['name' => 'Meter', 'symbol' => 'm', 'code' => 'm', 'to_base_factor' => 1000, 'to_base_offset' => 0],
                    ['name' => 'Kilometer', 'symbol' => 'km', 'code' => 'km', 'to_base_factor' => 1000000, 'to_base_offset' => 0],
                    ['name' => 'Inch', 'symbol' => 'in', 'code' => 'in', 'to_base_factor' => 25.4, 'to_base_offset' => 0],
                    ['name' => 'Foot', 'symbol' => 'ft', 'code' => 'ft', 'to_base_factor' => 304.8, 'to_base_offset' => 0],
                    ['name' => 'Yard', 'symbol' => 'yd', 'code' => 'yd', 'to_base_factor' => 914.4, 'to_base_offset' => 0],
                ],
            ],
            [
                'name' => 'Area',
                'base' => [
                    'name' => 'Square Meter',
                    'symbol' => 'm2',
                    'code' => 'm2',
                    'to_base_factor' => 1,
                    'to_base_offset' => 0,
                ],
                'units' => [
                    ['name' => 'Square Millimeter', 'symbol' => 'mm2', 'code' => 'mm2', 'to_base_factor' => 0.000001, 'to_base_offset' => 0],
                    ['name' => 'Square Centimeter', 'symbol' => 'cm2', 'code' => 'cm2', 'to_base_factor' => 0.0001, 'to_base_offset' => 0],
                    ['name' => 'Square Kilometer', 'symbol' => 'km2', 'code' => 'km2', 'to_base_factor' => 1000000, 'to_base_offset' => 0],
                    ['name' => 'Square Inch', 'symbol' => 'in2', 'code' => 'in2', 'to_base_factor' => 0.00064516, 'to_base_offset' => 0],
                    ['name' => 'Square Foot', 'symbol' => 'ft2', 'code' => 'ft2', 'to_base_factor' => 0.09290304, 'to_base_offset' => 0],
                ],
            ],
            [
                'name' => 'Count',
                'base' => [
                    'name' => 'Piece',
                    'symbol' => 'pc',
                    'code' => 'pc',
                    'to_base_factor' => 1,
                    'to_base_offset' => 0,
                ],
                'units' => [
                    ['name' => 'Dozen', 'symbol' => 'doz', 'code' => 'doz', 'to_base_factor' => 12, 'to_base_offset' => 0],
                ],
            ],
        ];
    }
}

