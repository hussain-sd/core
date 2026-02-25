<?php

namespace SmartTill\Core\Filament\Resources\Roles\Tables;

use App\Models\User;
use SmartTill\Core\Services\RoleAssignmentService;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $store = Filament::getTenant();
                $user = Filament::auth()->user();

                if (! $store || ! $user instanceof User) {
                    return $query->whereRaw('1 = 0');
                }

                $visibleRoleIds = app(RoleAssignmentService::class)
                    ->getVisibleStoreRoleIds($user, $store);

                if (empty($visibleRoleIds)) {
                    return $query->whereRaw('1 = 0');
                }

                return $query
                    ->where('store_id', $store->id)
                    ->where('panel', 'store')
                    ->where('is_system', false)
                    ->whereIn('id', $visibleRoleIds); // Exclude system roles
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Role Name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->description)
                    ->wrap(),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->badge()
                    ->color('success')
                    ->sortable(),

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
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Edit')
                        ->color('warning')
                        ->visible(fn ($record) => \SmartTill\Core\Filament\Resources\Roles\RoleResource::canEdit($record))
                        ->authorize(fn ($record) => \SmartTill\Core\Filament\Resources\Roles\RoleResource::canEdit($record)),
                    DeleteAction::make()
                        ->label('Delete')
                        ->color('danger')
                        ->visible(fn ($record) => \SmartTill\Core\Filament\Resources\Roles\RoleResource::canDelete($record))
                        ->authorize(fn ($record) => \SmartTill\Core\Filament\Resources\Roles\RoleResource::canDelete($record)),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => \SmartTill\Core\Filament\Resources\Roles\RoleResource::canDeleteAny())
                        ->authorize(fn () => \SmartTill\Core\Filament\Resources\Roles\RoleResource::canDeleteAny())
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $systemRoles = $records->filter(function ($record) {
                                return $record->is_system;
                            });

                            if ($systemRoles->isNotEmpty()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('System Role Deletion')
                                    ->body('System roles cannot be deleted.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $unauthorized = $records->filter(function ($record) {
                                return ! \SmartTill\Core\Filament\Resources\Roles\RoleResource::canDelete($record);
                            });

                            if ($unauthorized->isNotEmpty()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Unauthorized Role Deletion')
                                    ->body('You can only delete roles that you created.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $records->each->delete();

                            \Filament\Notifications\Notification::make()
                                ->title('Roles Deleted')
                                ->body('Selected roles have been deleted.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('name');
    }
}
