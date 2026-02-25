<?php

namespace SmartTill\Core\Services;

use SmartTill\Core\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RoleAssignmentService
{
    public function isAdminSuperAdmin(User $user): bool
    {
        return $this->hasAdminSuperAdmin($user);
    }

    public function isStoreSuperAdmin(User $user, Store $store): bool
    {
        return $this->hasStoreSuperAdmin($user, $store);
    }

    public function getVisibleAdminRoleIds(User $user): array
    {
        if ($this->hasAdminSuperAdmin($user)) {
            return Role::query()
                ->where('panel', 'admin')
                ->whereNull('store_id')
                ->where('is_system', false)
                ->orderBy('name')
                ->pluck('id')
                ->toArray();
        }

        $createdRoleIds = Role::query()
            ->where('panel', 'admin')
            ->whereNull('store_id')
            ->where('is_system', false)
            ->where('created_by', $user->id)
            ->pluck('id')
            ->toArray();

        $assignedRoleIds = DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->where('user_role.user_id', $user->id)
            ->whereNull('user_role.store_id')
            ->where('roles.panel', 'admin')
            ->whereNull('roles.store_id')
            ->where('roles.is_system', false)
            ->pluck('roles.id')
            ->toArray();

        return array_values(array_unique(array_merge($createdRoleIds, $assignedRoleIds)));
    }

    public function getVisibleStoreRoleIds(User $user, Store $store): array
    {
        if ($this->hasStoreSuperAdmin($user, $store)) {
            return Role::query()
                ->where('panel', 'store')
                ->where('store_id', $store->id)
                ->where('is_system', false)
                ->orderBy('name')
                ->pluck('id')
                ->toArray();
        }

        $createdRoleIds = Role::query()
            ->where('panel', 'store')
            ->where('store_id', $store->id)
            ->where('is_system', false)
            ->where('created_by', $user->id)
            ->pluck('id')
            ->toArray();

        $assignedRoleIds = DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->where('user_role.user_id', $user->id)
            ->where('user_role.store_id', $store->id)
            ->where('roles.panel', 'store')
            ->where('roles.store_id', $store->id)
            ->where('roles.is_system', false)
            ->pluck('roles.id')
            ->toArray();

        return array_values(array_unique(array_merge($createdRoleIds, $assignedRoleIds)));
    }

    public function canManageAdminRole(User $user, Role $role): bool
    {
        if ($role->is_system) {
            return false;
        }

        if ($this->hasAdminSuperAdmin($user)) {
            return true;
        }

        return (int) $role->created_by === (int) $user->id;
    }

    public function canManageStoreRole(User $user, Role $role, Store $store): bool
    {
        if ($role->is_system || (int) $role->store_id !== (int) $store->id) {
            return false;
        }

        if ($this->hasStoreSuperAdmin($user, $store)) {
            return true;
        }

        return (int) $role->created_by === (int) $user->id;
    }

    public function getAssignableAdminRoleIds(User $user): array
    {
        if ($this->hasAdminSuperAdmin($user)) {
            return Role::query()
                ->where('panel', 'admin')
                ->whereNull('store_id')
                ->where('is_system', false)
                ->orderBy('name')
                ->pluck('id')
                ->toArray();
        }

        return Role::query()
            ->where('panel', 'admin')
            ->whereNull('store_id')
            ->where('is_system', false)
            ->where('created_by', $user->id)
            ->orderBy('name')
            ->pluck('id')
            ->toArray();
    }

    public function getAssignableAdminRoleOptions(User $user): array
    {
        $assignableRoleIds = $this->getAssignableAdminRoleIds($user);

        if (empty($assignableRoleIds)) {
            return [];
        }

        return Role::query()
            ->whereIn('id', $assignableRoleIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function getAssignableStoreRoleIds(User $user, Store $store): array
    {
        if ($this->hasStoreSuperAdmin($user, $store)) {
            return Role::query()
                ->where('panel', 'store')
                ->where(function ($query) use ($store) {
                    $query->where(function ($query) use ($store) {
                        $query->where('store_id', $store->id)
                            ->where('is_system', false);
                    })->orWhere(function ($query) {
                        $query->whereNull('store_id')
                            ->where('is_system', true)
                            ->where('name', 'Super Admin');
                    });
                })
                ->orderBy('name')
                ->pluck('id')
                ->toArray();
        }

        return Role::query()
            ->where('panel', 'store')
            ->where('store_id', $store->id)
            ->where('is_system', false)
            ->where('created_by', $user->id)
            ->orderBy('name')
            ->pluck('id')
            ->toArray();
    }

    public function getAssignableStoreRoleOptions(User $user, Store $store): array
    {
        $assignableRoleIds = $this->getAssignableStoreRoleIds($user, $store);

        if (empty($assignableRoleIds)) {
            return [];
        }

        return Role::query()
            ->whereIn('id', $assignableRoleIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    private function hasAdminSuperAdmin(User $user): bool
    {
        return DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->where('user_role.user_id', $user->id)
            ->whereNull('user_role.store_id')
            ->where('roles.panel', 'admin')
            ->where('roles.name', 'Super Admin')
            ->whereNull('roles.store_id')
            ->where('roles.is_system', true)
            ->exists();
    }

    private function hasStoreSuperAdmin(User $user, Store $store): bool
    {
        return DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->where('user_role.user_id', $user->id)
            ->where('user_role.store_id', $store->id)
            ->where('roles.panel', 'store')
            ->where('roles.name', 'Super Admin')
            ->whereNull('roles.store_id')
            ->where('roles.is_system', true)
            ->exists();
    }
}
