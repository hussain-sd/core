<?php

namespace SmartTill\Core\Filament\Resources\Roles\Schemas;

use SmartTill\Core\Models\Permission;
use App\Models\User;
use SmartTill\Core\Services\PermissionScopeService;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role Information')
                    ->description('Basic role details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Role Name')
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: \SmartTill\Core\Models\Role::class,
                                column: 'name',
                                ignoreRecord: true,
                                modifyRuleUsing: function (\Illuminate\Validation\Rules\Unique $rule) {
                                    $store = Filament::getTenant();

                                    return $rule->where('store_id', $store?->id)->where('panel', 'store');
                                }
                            )
                            ->helperText('A descriptive name for this role (e.g., Store Manager, Cashier)')
                            ->placeholder('Store Manager')
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Brief description of what this role can do')
                            ->placeholder('This role can manage sales, inventory, and reports...')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Permissions')
                    ->description('Select permissions for this role. Permissions are organized by resource groups.')
                    ->schema([
                        TextInput::make('permission_search')
                            ->label('Search Permissions')
                            ->placeholder('Type to search permissions...')
                            ->prefixIcon(Heroicon::OutlinedMagnifyingGlass)
                            ->live()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        Tabs::make('Permissions Tabs')
                            ->tabs(self::getPermissionTabs()),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected static function getPermissionTabs(): array
    {
        $user = Filament::auth()->user();
        $store = Filament::getTenant();

        if (! $user instanceof User || ! $store) {
            return [];
        }

        $assignablePermissionIds = app(PermissionScopeService::class)
            ->getAssignableStorePermissionIds($user, $store);

        if (empty($assignablePermissionIds)) {
            return [];
        }

        // Only get store panel permissions
        $permissionsByGroup = Permission::query()
            ->where('panel', 'store')
            ->whereIn('id', $assignablePermissionIds)
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->toBase()
            ->groupBy('group');

        $resourceGroups = $permissionsByGroup->reject(fn ($_, $group) => $group === 'Widgets');
        $widgetGroups = $permissionsByGroup->only(['Widgets']);

        return [
            Tab::make('Resources')
                ->schema(self::buildPermissionFieldsets($resourceGroups)),
            Tab::make('Widgets')
                ->schema(self::buildPermissionFieldsets($widgetGroups)),
        ];
    }

    protected static function buildPermissionFieldsets(Collection $permissionsByGroup): array
    {
        $fieldsets = [];

        foreach ($permissionsByGroup as $group => $groupPermissions) {
            $groupLabel = $group ?: 'Other';
            $groupKey = 'permissions_'.str_replace(' ', '_', strtolower($group ?: 'other'));

            $fieldsets[] = Fieldset::make($groupLabel)
                ->schema([
                    CheckboxList::make($groupKey)
                        ->label('')
                        ->options(function (Get $get) use ($groupPermissions) {
                            $search = strtolower($get('permission_search') ?? '');

                            $filtered = $groupPermissions->filter(function ($permission) use ($search) {
                                if (empty($search)) {
                                    return true;
                                }

                                return str_contains(strtolower($permission->name), $search) ||
                                       str_contains(strtolower($permission->description ?? ''), $search);
                            });

                            return $filtered->mapWithKeys(function ($permission) {
                                return [$permission->id => $permission->name];
                            })->toArray();
                        })
                        ->descriptions(function (Get $get) use ($groupPermissions) {
                            $search = strtolower($get('permission_search') ?? '');

                            $filtered = $groupPermissions->filter(function ($permission) use ($search) {
                                if (empty($search)) {
                                    return true;
                                }

                                return str_contains(strtolower($permission->name), $search) ||
                                       str_contains(strtolower($permission->description ?? ''), $search);
                            });

                            return $filtered->mapWithKeys(function ($permission) {
                                return [$permission->id => $permission->description];
                            })->toArray();
                        })
                        ->bulkToggleable()
                        ->gridDirection('row')
                        ->columns(4)
                        ->hiddenLabel()
                        ->dehydrated()
                        ->columnSpanFull(),
                ])
                ->visible(function (Get $get) use ($groupPermissions) {
                    $search = strtolower($get('permission_search') ?? '');

                    if (empty($search)) {
                        return true;
                    }

                    return $groupPermissions->filter(function ($permission) use ($search) {
                        return str_contains(strtolower($permission->name), $search) ||
                               str_contains(strtolower($permission->description ?? ''), $search);
                    })->isNotEmpty();
                })
                ->columnSpanFull();
        }

        return $fieldsets;
    }
}
