<?php

namespace SmartTill\Core\Services;

use SmartTill\Core\Models\Permission;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermissionScopeService
{
    public function getAssignableAdminPermissionIds(User $user): array
    {
        if (app(RoleAssignmentService::class)->isAdminSuperAdmin($user)) {
            return Permission::query()
                ->where('panel', 'admin')
                ->orderBy('name')
                ->pluck('id')
                ->toArray();
        }

        return DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->join('role_has_permissions', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('user_role.user_id', $user->id)
            ->whereNull('user_role.store_id')
            ->where('roles.panel', 'admin')
            ->whereNull('roles.store_id')
            ->where('permissions.panel', 'admin')
            ->pluck('permissions.id')
            ->unique()
            ->values()
            ->toArray();
    }

    public function getAssignableStorePermissionIds(User $user, Store $store): array
    {
        if (app(RoleAssignmentService::class)->isStoreSuperAdmin($user, $store)) {
            return Permission::query()
                ->where('panel', 'store')
                ->orderBy('name')
                ->pluck('id')
                ->toArray();
        }

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
            ->where('permissions.panel', 'store')
            ->pluck('permissions.id')
            ->unique()
            ->values()
            ->toArray();
    }
}
