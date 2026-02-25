<?php

namespace SmartTill\Core\Filament\Resources\Roles\Pages;

use SmartTill\Core\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use SmartTill\Core\Services\PermissionScopeService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected array $permissionIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $store = Filament::getTenant();

        $data['store_id'] = $store->id;
        $data['panel'] = 'store';
        $data['is_system'] = false;
        $data['created_by'] = Filament::auth()->id();

        // Get form state to collect all permission IDs from group fields
        $formState = $this->form->getRawState();
        $permissionIds = [];

        foreach ($formState as $key => $value) {
            if (str_starts_with($key, 'permissions_') && is_array($value)) {
                $permissionIds = array_merge($permissionIds, $value);
            }
        }

        // Clean and store permission IDs
        $permissionIds = array_values(array_unique(array_filter($permissionIds, fn ($id) => ! empty($id) && is_numeric($id))));

        $authUser = Filament::auth()->user();
        if ($authUser instanceof User) {
            $assignablePermissionIds = app(PermissionScopeService::class)
                ->getAssignableStorePermissionIds($authUser, $store);
            $permissionIds = array_values(array_intersect($permissionIds, $assignablePermissionIds));
        } else {
            $permissionIds = [];
        }

        $this->permissionIds = $permissionIds;

        // Remove permission fields from data (we'll sync manually)
        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'permissions_')) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! empty($this->permissionIds)) {
            $this->record->permissions()->sync($this->permissionIds);
        }
    }
}
