<?php

namespace SmartTill\Core\Filament\Resources\Helpers;

use Filament\Tables\Columns\TextColumn;

class SyncReferenceColumn
{
    public static function make(): TextColumn
    {
        return TextColumn::make('reference')
            ->label('Reference')
            ->state(fn ($record): ?string => filled($record->reference ?? null)
                ? (string) $record->reference
                : (filled($record->server_id ?? null)
                    ? (string) $record->server_id
                    : (filled($record->local_id ?? null) ? (string) $record->local_id : null)))
            ->formatStateUsing(fn ($state): string => filled($state) ? (string) $state : '—')
            ->description(fn ($record): ?string => filled($record->server_id ?? null) && filled($record->local_id ?? null)
                ? (string) $record->local_id
                : null)
            ->searchable(['reference', 'server_id', 'local_id'])
            ->sortable();
    }
}
