<?php

namespace SmartTill\Core\Filament\Resources\Helpers;

use Filament\Facades\Filament;

class ResourceCanAccessHelper
{
    public static function check(string $permission): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof \App\Models\User) {
            return false;
        }

        if (method_exists($user, 'shouldBypassCorePermissions') && $user->shouldBypassCorePermissions()) {
            return true;
        }

        $store = Filament::getTenant();

        if (! $store) {
            return false;
        }

        // Check if user has Super Admin role for this store
        $hasSuperAdmin = \Illuminate\Support\Facades\DB::table('user_role')
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

        // Direct permission check using DB query
        // Role must belong to this store (roles.store_id = store.id) OR be a system role (roles.store_id IS NULL)
        $hasPermission = \Illuminate\Support\Facades\DB::table('user_role')
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
            ->where('permissions.name', $permission)
            ->where('permissions.panel', 'store')
            ->exists();

        return $hasPermission;
    }
}
