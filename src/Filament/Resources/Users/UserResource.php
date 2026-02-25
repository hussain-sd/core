<?php

namespace SmartTill\Core\Filament\Resources\Users;

use SmartTill\Core\Filament\Resources\Users\Pages\ListUsers;
use SmartTill\Core\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getActiveNavigationIcon(): BackedEnum|Htmlable|null|string
    {
        return Heroicon::Users;
    }

    protected static string|UnitEnum|null $navigationGroup = 'User Management';

    protected static ?int $navigationSort = 1;

    protected static bool $isScopedToTenant = false;

    public static function canAccess(): bool
    {
        return ResourceCanAccessHelper::check('View Users');
    }

    public static function canViewAny(): bool
    {
        return ResourceCanAccessHelper::check('View Users');
    }

    public static function canView($record): bool
    {
        return ResourceCanAccessHelper::check('View Users');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canRestore($record): bool
    {
        return false;
    }

    public static function canForceDelete($record): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record->name;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Phone' => $record->phone,
            'Email' => $record->email,
        ];
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $storeId = filament()->getTenant()->id;

        return parent::getEloquentQuery()
            ->whereHas('stores', function (Builder $query) use ($storeId) {
                $query->where('store_id', $storeId);
            });
    }
}
