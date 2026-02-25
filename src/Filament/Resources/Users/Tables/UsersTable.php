<?php

namespace SmartTill\Core\Filament\Resources\Users\Tables;

use App\Models\User;
use SmartTill\Core\Services\RoleAssignmentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use SmartTill\Core\Services\CashService;
use SmartTill\Core\Services\UserStoreCashService;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(['name', 'phone'])
                    ->description(fn ($record) => $record->phone),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('roles')
                    ->label('Roles')
                    ->badge()
                    ->separator(',')
                    ->color('info')
                    ->getStateUsing(function ($record) {
                        $store = \Filament\Facades\Filament::getTenant();
                        if (! $store) {
                            return [];
                        }

                        // Get roles for this user and store
                        $roles = DB::table('user_role')
                            ->join('roles', 'user_role.role_id', '=', 'roles.id')
                            ->where('user_role.user_id', $record->id)
                            ->where('user_role.store_id', $store->id)
                            ->where('roles.panel', 'store')
                            ->pluck('roles.name')
                            ->toArray();

                        return empty($roles) ? ['No roles'] : $roles;
                    }),
                TextColumn::make('cash_in_hand')
                    ->label('Cash in Hand')
                    ->money(fn () => Filament::getTenant()?->currency?->code ?? 'PKR')
                    ->getStateUsing(function ($record) {
                        $store = \Filament\Facades\Filament::getTenant();
                        if (! $store) {
                            return 0;
                        }

                        return app(UserStoreCashService::class)->getCashInHandForStore($record, $store);
                    })
                    ->color(function ($record) {
                        $store = \Filament\Facades\Filament::getTenant();
                        if (! $store) {
                            return 'gray';
                        }
                        $cashInHand = app(UserStoreCashService::class)->getCashInHandForStore($record, $store);

                        return $cashInHand > 0 ? 'success' : 'gray';
                    }),
                TextColumn::make('email_verified_at')
                    ->dateTime()
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
            ->defaultSort('id', 'desc')
            ->filters([
                // No filters needed for simple store detachment
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('collect_cash')
                        ->label('Collect Cash')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->color('success')
                        ->visible(function ($record) {
                            if (! self::canManageUserRecord($record)) {
                                return false;
                            }

                            if (! \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Collect Cash from Users')) {
                                return false;
                            }
                            $store = \Filament\Facades\Filament::getTenant();
                            if (! $store) {
                                return false;
                            }

                            return app(UserStoreCashService::class)->getCashInHandForStore($record, $store) > 0;
                        })
                        ->authorize(fn ($record) => self::canManageUserRecord($record) && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Collect Cash from Users'))
                        ->requiresConfirmation()
                        ->modalHeading('Collect Cash')
                        ->modalDescription(function ($record) {
                            $store = \Filament\Facades\Filament::getTenant();
                            if (! $store) {
                                return "Collect cash from {$record->name}.";
                            }
                            $cashInHand = app(UserStoreCashService::class)->getCashInHandForStore($record, $store);

                            return "Collect cash from {$record->name}. Current cash in hand: ".number_format($cashInHand, 2).' PKR';
                        })
                        ->modalSubmitActionLabel('Collect Cash')
                        ->schema([
                            Textarea::make('note')
                                ->label('Note')
                                ->placeholder('Optional note for this cash collection')
                                ->rows(3),
                        ])
                        ->action(function (array $data, $record) {
                            try {
                                if (! self::canManageUserRecord($record) || ! \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Collect Cash from Users')) {
                                    throw new \Exception('You do not have permission to collect cash from this user.');
                                }

                                $store = \Filament\Facades\Filament::getTenant();
                                if (! $store) {
                                    throw new \Exception('No store context available');
                                }
                                $cashService = app(CashService::class);
                                $cashService->collectCash($record, Auth::user(), $data['note'] ?? null, $store);

                                Notification::make()
                                    ->title('Cash Collected')
                                    ->body("Cash has been successfully collected from {$record->name}.")
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Error')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Action::make('manage_roles')
                        ->label('Manage Roles')
                        ->icon(Heroicon::OutlinedShieldCheck)
                        ->color('warning')
                        ->visible(fn ($record) => self::canManageRoles() && self::canManageUserRecord($record) && $record->id !== Auth::id())
                        ->authorize(fn ($record) => self::canManageRoles() && self::canManageUserRecord($record) && $record->id !== Auth::id())
                        ->modalHeading(fn ($record) => "Manage Roles for {$record->name}")
                        ->modalDescription('Assign or remove roles for this user in this store.')
                        ->modalWidth('4xl')
                        ->schema(function (Schema $schema) {
                            $store = Filament::getTenant();
                            if (! $store) {
                                return $schema->components([]);
                            }

                            return $schema->components([
                                Select::make('roles')
                                    ->label('Roles')
                                    ->multiple()
                                    ->options(function () use ($store) {
                                        $authUser = Filament::auth()->user();
                                        if (! $authUser instanceof User) {
                                            return [];
                                        }

                                        return app(RoleAssignmentService::class)
                                            ->getAssignableStoreRoleOptions($authUser, $store);
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->placeholder('Select roles to assign'),
                            ]);
                        })
                        ->fillForm(function ($record) {
                            $store = Filament::getTenant();
                            if (! $store) {
                                return ['roles' => []];
                            }

                            $assignedRoles = DB::table('user_role')
                                ->where('user_id', $record->id)
                                ->where('store_id', $store->id)
                                ->pluck('role_id')
                                ->toArray();

                            return [
                                'roles' => $assignedRoles,
                            ];
                        })
                        ->action(function (array $data, $record) {
                            $user = Filament::auth()->user();
                            $store = Filament::getTenant();

                            if (! $user instanceof User || ! $store || ! self::canManageRoles() || ! self::canManageUserRecord($record)) {
                                Notification::make()
                                    ->title('Unauthorized')
                                    ->body('You do not have permission to assign roles.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $storeId = $store->id;
                            $selectedRoleIds = $data['roles'] ?? [];
                            $assignableRoleIds = app(RoleAssignmentService::class)
                                ->getAssignableStoreRoleIds($user, $store);
                            $invalidRoleIds = array_diff($selectedRoleIds, $assignableRoleIds);

                            if (! empty($invalidRoleIds)) {
                                Notification::make()
                                    ->title('Unauthorized Role Assignment')
                                    ->body('You can only assign roles that are explicitly granted to you.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            // Get currently assigned role IDs for this store
                            $currentRoleIds = DB::table('user_role')
                                ->where('user_id', $record->id)
                                ->where('store_id', $storeId)
                                ->pluck('role_id')
                                ->toArray();

                            $manageableCurrentRoleIds = array_intersect($currentRoleIds, $assignableRoleIds);

                            // Detach roles that are no longer selected
                            $rolesToDetach = array_diff($manageableCurrentRoleIds, $selectedRoleIds);
                            if (! empty($rolesToDetach)) {
                                DB::table('user_role')
                                    ->where('user_id', $record->id)
                                    ->where('store_id', $storeId)
                                    ->whereIn('role_id', $rolesToDetach)
                                    ->delete();
                            }

                            // Attach new roles
                            $rolesToAttach = array_diff($selectedRoleIds, $currentRoleIds);
                            if (! empty($rolesToAttach)) {
                                $insertData = collect($rolesToAttach)->map(function ($roleId) use ($record, $storeId) {
                                    return [
                                        'user_id' => $record->id,
                                        'role_id' => $roleId,
                                        'store_id' => $storeId,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ];
                                })->toArray();

                                DB::table('user_role')->insert($insertData);
                            }

                            Notification::make()
                                ->title('Roles Updated')
                                ->body('User roles have been updated successfully.')
                                ->success()
                                ->send();
                        }),

                    DeleteAction::make()
                        ->label('Remove from Store')
                        ->visible(fn ($record) => self::canManageUserRecord($record) && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Remove Users') && $record->id !== Auth::id())
                        ->authorize(fn ($record) => self::canManageUserRecord($record) && \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Remove Users') && $record->id !== Auth::id())
                        ->requiresConfirmation()
                        ->modalHeading('Remove User from Store')
                        ->modalDescription('Are you sure you want to remove this user from this store? This action will revoke their access to this store immediately.')
                        ->modalSubmitActionLabel('Remove from Store')
                        ->action(function ($record) {
                            if (! self::canManageUserRecord($record) || ! \SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper::check('Remove Users')) {
                                Notification::make()
                                    ->title('Unauthorized')
                                    ->body('You do not have permission to remove this user.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $store = filament()->getTenant();

                            // Remove user from store
                            $record->stores()->detach($store->id);

                            Notification::make()
                                ->title('User Removed')
                                ->body('The user has been successfully removed from this store.')
                                ->success()
                                ->send();
                        }),

                ]),
            ]);
    }

    private static function canManageRoles(): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $store = Filament::getTenant();

        if (! $store) {
            return false;
        }

        // Check if user has Super Admin role for this store
        $hasSuperAdmin = DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->where('user_role.user_id', $user->id)
            ->where('user_role.store_id', $store->id)
            ->where('roles.panel', 'store')
            ->where('roles.name', 'Super Admin')
            ->where(function ($query) {
                $query->where('roles.is_system', '=', 1)
                    ->orWhere('roles.is_system', '=', true);
            })
            ->whereNull('roles.store_id')
            ->exists();

        if ($hasSuperAdmin) {
            return true;
        }

        // Check if user has permission to manage roles (requires "View Roles" permission at minimum)
        return DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->join('role_has_permissions', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('user_role.user_id', $user->id)
            ->where('user_role.store_id', $store->id)
            ->where('roles.panel', 'store')
            ->where(function ($query) use ($store) {
                $query->where('roles.store_id', $store->id)
                    ->orWhereNull('roles.store_id');
            })
            ->where('permissions.name', 'View Roles')
            ->where('permissions.panel', 'store')
            ->exists();
    }

    private static function canManageUserRecord($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $store = Filament::getTenant();
        if ($store && app(RoleAssignmentService::class)->isStoreSuperAdmin($user, $store)) {
            return true;
        }

        return (int) $record->created_by === (int) $user->id;
    }
}
