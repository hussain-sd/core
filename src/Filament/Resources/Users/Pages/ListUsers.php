<?php

namespace SmartTill\Core\Filament\Resources\Users\Pages;

use SmartTill\Core\Filament\Resources\Users\UserResource;
use SmartTill\Core\Filament\Resources\Users\Widgets\RecentInvitationsWidget;
use Filament\Resources\Pages\ListRecords;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No header actions - users can only be added through invitation
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            // No header widgets
        ];
    }

    protected function getFooterWidgets(): array
    {
        if (! ResourceCanAccessHelper::check('View Invitations')) {
            return [];
        }

        return [RecentInvitationsWidget::class];
    }
}
