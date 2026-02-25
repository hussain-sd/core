<?php

namespace SmartTill\Core\Filament\Resources\Roles\Pages;

use SmartTill\Core\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use SmartTill\Core\Services\PermissionScopeService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected array $permissionIds = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $role = $this->record;
        $role->load('permissions');

        // Get all permissions grouped by their group
        $permissionsByGroup = $role->permissions->groupBy('group');

        // Populate group-specific fields
        foreach ($permissionsByGroup as $group => $groupPermissions) {
            $groupKey = 'permissions_'.str_replace(' ', '_', strtolower($group ?: 'other'));
            $data[$groupKey] = $groupPermissions->pluck('id')->toArray();
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
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

        $store = Filament::getTenant();
        $authUser = Filament::auth()->user();
        if ($store && $authUser instanceof User) {
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

    protected function afterSave(): void
    {
        if (! empty($this->permissionIds)) {
            $this->record->permissions()->sync($this->permissionIds);
        } else {
            $this->record->permissions()->detach();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->disabled(fn () => $this->record->is_system)
                ->action(function () {
                    if ($this->record->is_system) {
                        \Filament\Notifications\Notification::make()
                            ->title('Cannot Delete System Role')
                            ->body('System roles cannot be deleted.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->delete();

                    \Filament\Notifications\Notification::make()
                        ->title('Role Deleted')
                        ->body('The role has been deleted successfully.')
                        ->success()
                        ->send();

                    $this->redirect(RoleResource::getUrl('index'));
                }),
        ];
    }
}
