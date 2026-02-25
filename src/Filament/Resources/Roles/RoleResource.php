<?php

namespace SmartTill\Core\Filament\Resources\Roles;

use SmartTill\Core\Filament\Resources\Roles\Pages\CreateRole;
use SmartTill\Core\Filament\Resources\Roles\Pages\EditRole;
use SmartTill\Core\Filament\Resources\Roles\Pages\ListRoles;
use SmartTill\Core\Filament\Resources\Roles\Schemas\RoleForm;
use SmartTill\Core\Filament\Resources\Roles\Tables\RolesTable;
use SmartTill\Core\Models\Role;
use App\Models\User;
use SmartTill\Core\Services\RoleAssignmentService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function getActiveNavigationIcon(): BackedEnum|string|null
    {
        return Heroicon::ShieldCheck;
    }

    protected static ?string $recordTitleAttribute = 'name';

    protected static UnitEnum|string|null $navigationGroup = 'User Management';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Roles';

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return ResourceCanAccessHelper::check('View Roles');
    }

    public static function canViewAny(): bool
    {
        return ResourceCanAccessHelper::check('View Roles');
    }

    public static function canView($record): bool
    {
        $user = Filament::auth()->user();
        $store = Filament::getTenant();

        if (! $user instanceof User || ! $store) {
            return false;
        }

        if (! ResourceCanAccessHelper::check('View Roles')) {
            return false;
        }

        $visibleRoleIds = app(RoleAssignmentService::class)->getVisibleStoreRoleIds($user, $store);

        return in_array($record->id, $visibleRoleIds, true);
    }

    public static function canCreate(): bool
    {
        return ResourceCanAccessHelper::check('Create Roles');
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();
        $store = Filament::getTenant();

        if (! $user instanceof User || ! $store) {
            return false;
        }

        return ResourceCanAccessHelper::check('Edit Roles')
            && app(RoleAssignmentService::class)->canManageStoreRole($user, $record, $store);
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();
        $store = Filament::getTenant();

        if (! $user instanceof User || ! $store) {
            return false;
        }

        return ResourceCanAccessHelper::check('Delete Roles')
            && app(RoleAssignmentService::class)->canManageStoreRole($user, $record, $store);
    }

    public static function canDeleteAny(): bool
    {
        $user = Filament::auth()->user();
        $store = Filament::getTenant();

        if (! $user instanceof User || ! $store) {
            return false;
        }

        if (! ResourceCanAccessHelper::check('Delete Roles')) {
            return false;
        }

        if (app(RoleAssignmentService::class)->isStoreSuperAdmin($user, $store)) {
            return true;
        }

        return Role::query()
            ->where('panel', 'store')
            ->where('store_id', $store->id)
            ->where('is_system', false)
            ->where('created_by', $user->id)
            ->exists();
    }

    public static function canRestore($record): bool
    {
        return false;
    }

    public static function canForceDelete($record): bool
    {
        return false;
    }
}
