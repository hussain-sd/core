<?php

namespace SmartTill\Core\Filament\Resources\Helpers;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SyncReferenceColumn
{
    /**
     * @var array<string, array<int, string>>
     */
    private static array $searchableColumnsCache = [];

    public static function make(bool $isToggledHiddenByDefault = true): TextColumn
    {
        return TextColumn::make('reference')
            ->label('Reference')
            ->state(fn ($record): ?string => filled($record->reference ?? null)
                ? (string) $record->reference
                : (filled($record->server_id ?? null)
                    ? (string) $record->server_id
                    : (filled($record->local_id ?? null) ? (string) $record->local_id : null)))
            ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '—')
            ->description(fn ($record): ?string => filled($record->local_id ?? null)
                ? (string) $record->local_id
                : null)
            ->searchable(query: function (Builder $query, string $search): Builder {
                $columns = self::resolveSearchableColumns($query->getModel());

                if ($columns === []) {
                    return $query;
                }

                return $query->where(function (Builder $innerQuery) use ($columns, $search): Builder {
                    foreach ($columns as $index => $column) {
                        if ($index === 0) {
                            $innerQuery->where($column, 'like', "%{$search}%");
                        } else {
                            $innerQuery->orWhere($column, 'like', "%{$search}%");
                        }
                    }

                    return $innerQuery;
                });
            })
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: $isToggledHiddenByDefault);
    }

    /**
     * @return array<int, string>
     */
    private static function resolveSearchableColumns(Model $model): array
    {
        $connectionName = $model->getConnectionName() ?? config('database.default');
        $table = $model->getTable();
        $cacheKey = "{$connectionName}.{$table}";

        if (isset(self::$searchableColumnsCache[$cacheKey])) {
            return self::$searchableColumnsCache[$cacheKey];
        }

        $candidateColumns = ['reference', 'server_id', 'local_id'];

        $searchableColumns = array_values(array_filter(
            $candidateColumns,
            fn (string $column): bool => Schema::connection($connectionName)->hasColumn($table, $column),
        ));

        self::$searchableColumnsCache[$cacheKey] = $searchableColumns;

        return $searchableColumns;
    }
}
