<?php

namespace SmartTill\Core\Filament\Resources\Units\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ImportAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use SmartTill\Core\Filament\Exports\UnitExporter;
use SmartTill\Core\Filament\Imports\UnitImporter;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Filament\Resources\Helpers\SyncReferenceColumn;

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SyncReferenceColumn::make(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('scope')
                    ->label('Scope')
                    ->badge()
                    ->color(fn ($record) => $record->store_id === null ? 'info' : 'success')
                    ->getStateUsing(fn ($record) => $record->store_id === null ? 'Global' : 'Store'),
                TextColumn::make('dimension.name')
                    ->label('Dimension')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('symbol')
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('to_base_factor')
                    ->label('Factor')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('to_base_offset')
                    ->label('Offset')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_base')
                    ->label('Base')
                    ->boolean()
                    ->state(fn ($record) => $record->dimension?->base_unit_id === $record->id)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->label('Deleted at')
                    ->dateTime()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->created_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A'))
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Updated at')
                    ->since()
                    ->timezone(fn () => Filament::getTenant()?->timezone?->name ?? 'UTC')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->updated_at?->setTimezone(Filament::getTenant()?->timezone?->name ?? 'UTC')->format('M d, Y g:i A'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                ImportAction::make()
                    ->importer(UnitImporter::class)
                    ->options([
                        'store_id' => Filament::getTenant()?->getKey(),
                    ])
                    ->visible(fn () => ResourceCanAccessHelper::check('Import Units'))
                    ->authorize(fn () => ResourceCanAccessHelper::check('Import Units')),
            ])
            ->defaultSort('id', 'desc')
            ->groups([
                'dimension.name',
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Edit')
                        ->color('warning')
                        ->visible(fn ($record) => \SmartTill\Core\Filament\Resources\Units\UnitResource::canEdit($record))
                        ->authorize(fn ($record) => \SmartTill\Core\Filament\Resources\Units\UnitResource::canEdit($record)),
                    DeleteAction::make()
                        ->label('Delete')
                        ->color('danger')
                        ->visible(fn ($record) => ! $record->trashed() && \SmartTill\Core\Filament\Resources\Units\UnitResource::canDelete($record))
                        ->authorize(fn ($record) => \SmartTill\Core\Filament\Resources\Units\UnitResource::canDelete($record)),
                    RestoreAction::make()
                        ->label('Restore')
                        ->color('success')
                        ->visible(fn ($record) => $record->trashed() && \SmartTill\Core\Filament\Resources\Units\UnitResource::canRestore($record))
                        ->authorize(fn ($record) => \SmartTill\Core\Filament\Resources\Units\UnitResource::canRestore($record)),
                    ForceDeleteAction::make()
                        ->label('Force delete')
                        ->color('warning')
                        ->visible(fn ($record) => $record->trashed() && \SmartTill\Core\Filament\Resources\Units\UnitResource::canForceDelete($record))
                        ->authorize(fn ($record) => \SmartTill\Core\Filament\Resources\Units\UnitResource::canForceDelete($record)),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn ($records) => $records->every(fn ($record) => ! $record->trashed() && \SmartTill\Core\Filament\Resources\Units\UnitResource::canDelete($record)))
                        ->authorize(fn ($records) => $records->every(fn ($record) => \SmartTill\Core\Filament\Resources\Units\UnitResource::canDelete($record))),
                    RestoreBulkAction::make()
                        ->visible(fn ($records) => $records->every(fn ($record) => $record->trashed() && \SmartTill\Core\Filament\Resources\Units\UnitResource::canRestore($record)))
                        ->authorize(fn ($records) => $records->every(fn ($record) => \SmartTill\Core\Filament\Resources\Units\UnitResource::canRestore($record))),
                    ForceDeleteBulkAction::make()
                        ->visible(fn ($records) => $records->every(fn ($record) => $record->trashed() && \SmartTill\Core\Filament\Resources\Units\UnitResource::canForceDelete($record)))
                        ->authorize(fn ($records) => $records->every(fn ($record) => \SmartTill\Core\Filament\Resources\Units\UnitResource::canForceDelete($record))),
                    ExportBulkAction::make()
                        ->exporter(UnitExporter::class)
                        ->visible(fn () => ResourceCanAccessHelper::check('Export Units'))
                        ->authorize(fn () => ResourceCanAccessHelper::check('Export Units')),
                ]),
            ]);
    }
}
