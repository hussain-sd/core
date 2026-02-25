<?php

namespace SmartTill\Core\Services;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SmartTill\Core\Models\Permission;
use SmartTill\Core\Models\Role;
use SmartTill\Core\Support\CorePermissionCatalog;

class CoreAccessBootstrapService
{
    public function ensureCoreAccess(?string $connection = null): void
    {
        $definitions = $this->getPermissionDefinitions();

        $this->syncPermissionsForPanel('store', $definitions['store'] ?? [], $connection);
        $this->ensureSuperAdminRole('store', $connection);
    }

    public function assignStoreSuperAdmin(User $user, Store $store, ?string $connection = null): void
    {
        $roleQuery = $connection ? Role::on($connection) : Role::query();

        $storeSuperAdminRole = $roleQuery
            ->where('name', 'Super Admin')
            ->where('panel', 'store')
            ->whereNull('store_id')
            ->where('is_system', true)
            ->first();

        if (! $storeSuperAdminRole) {
            return;
        }

        if (Schema::connection($connection)->hasTable('store_user') && ! DB::connection($connection)->table('store_user')
            ->where('store_id', $store->id)
            ->where('user_id', $user->id)
            ->exists()) {
            $storeUserData = [
                'store_id' => $store->id,
                'user_id' => $user->id,
            ];

            if (Schema::connection($connection)->hasColumn('store_user', 'cash_in_hand')) {
                $storeUserData['cash_in_hand'] = 0;
            }

            if (Schema::connection($connection)->hasColumn('store_user', 'role_id')) {
                $storeUserData['role_id'] = $storeSuperAdminRole->id;
            }

            if (Schema::connection($connection)->hasColumn('store_user', 'created_at')) {
                $storeUserData['created_at'] = now();
            }

            if (Schema::connection($connection)->hasColumn('store_user', 'updated_at')) {
                $storeUserData['updated_at'] = now();
            }

            DB::connection($connection)->table('store_user')->insert($storeUserData);
        }

        if (! DB::connection($connection)->table('user_role')
            ->where('user_id', $user->id)
            ->where('role_id', $storeSuperAdminRole->id)
            ->where('store_id', $store->id)
            ->exists()) {
            DB::connection($connection)->table('user_role')->insert([
                'user_id' => $user->id,
                'role_id' => $storeSuperAdminRole->id,
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function syncPermissionsForPanel(string $panel, array $groups, ?string $connection): void
    {
        foreach ($groups as $group => $permissions) {
            foreach ($permissions as $permissionData) {
                $name = $permissionData['name'] ?? null;
                if (! is_string($name) || $name === '') {
                    continue;
                }

                $permissionQuery = $connection ? Permission::on($connection) : Permission::query();

                $permissionQuery->updateOrCreate(
                    ['name' => $name],
                    [
                        'name' => $name,
                        'group' => is_string($group) ? $group : null,
                        'description' => $permissionData['description'] ?? null,
                        'panel' => $panel,
                    ]
                );
            }
        }
    }

    private function ensureSuperAdminRole(string $panel, ?string $connection): void
    {
        $roleQuery = $connection ? Role::on($connection) : Role::query();

        $role = $roleQuery->firstOrCreate(
            [
                'name' => 'Super Admin',
                'panel' => $panel,
                'store_id' => null,
            ],
            [
                'description' => "Super Admin role for {$panel} panel with all permissions",
                'is_system' => true,
            ]
        );

        $permissionQuery = $connection ? Permission::on($connection) : Permission::query();

        $permissionIds = $permissionQuery
            ->where('panel', $panel)
            ->pluck('id');

        DB::connection($connection)->table('role_has_permissions')
            ->where('role_id', $role->id)
            ->delete();

        if ($permissionIds->isNotEmpty()) {
            $rows = $permissionIds
                ->map(fn ($permissionId) => [
                    'role_id' => $role->id,
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all();

            DB::connection($connection)->table('role_has_permissions')->insert($rows);
        }
    }

    private function getPermissionDefinitions(): array
    {
        $configPermissions = config('permissions');

        if (is_array($configPermissions) && isset($configPermissions['store']) && is_array($configPermissions['store'])) {
            return [
                'store' => $configPermissions['store'],
                'admin' => is_array($configPermissions['admin'] ?? null) ? $configPermissions['admin'] : [],
            ];
        }

        return CorePermissionCatalog::definitions();
    }
}
