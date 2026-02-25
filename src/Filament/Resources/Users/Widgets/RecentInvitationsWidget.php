<?php

namespace SmartTill\Core\Filament\Resources\Users\Widgets;

use SmartTill\Core\Models\Invitation;
use App\Models\User;
use SmartTill\Core\Services\RoleAssignmentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;

class RecentInvitationsWidget extends TableWidget
{
    protected static ?string $heading = 'Recent Invitations';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $store = filament()->getTenant();

        return $table
            ->query(
                $this->canViewInvitations()
                    ? Invitation::query()
                        ->where('store_id', $store?->id)
                        ->with('role')
                        ->latest()
                    : Invitation::query()->whereRaw('1 = 0')
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role.name')
                    ->label('Role')
                    ->badge()
                    ->color('info')
                    ->default('No Role'),
                TextColumn::make('status')
                    ->label('Status')
                    ->getStateUsing(function ($record) {
                        if ($record->isAccepted()) {
                            return 'Accepted';
                        }

                        if ($record->isExpired()) {
                            return 'Expired';
                        }

                        return 'Pending';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Accepted' => 'success',
                        'Pending' => 'warning',
                        'Expired' => 'danger',
                    }),
            ])
            ->headerActions([
                Action::make('invite_user')
                    ->label('Invite User')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->color('primary')
                    ->visible(fn () => $this->canInvite())
                    ->authorize(fn () => $this->canInvite())
                    ->modalSubmitActionLabel('Invite')
                    ->schema([
                        TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->required()
                            ->placeholder('user@example.com')
                            ->helperText('Enter the email address of the person you want to invite to this store')
                            ->validationAttribute('email address')
                            ->rules([
                                'required',
                                'string',
                                'max:255',
                                Rule::email()
                                    ->rfcCompliant(strict: true)
                                    ->validateMxRecord()
                                    ->preventSpoofing(),
                            ]),

                        Select::make('role_id')
                            ->label('Role')
                            ->searchable()
                            ->preload()
                            ->placeholder('Select a role (optional)')
                            ->helperText('Assign a role to this user. They will have the permissions associated with this role.')
                            ->visible(fn () => $this->canAssignRoles())
                            ->options(function () {
                                $store = filament()->getTenant();
                                if (! $store) {
                                    return [];
                                }

                                $authUser = Auth::user();
                                if (! $authUser instanceof User) {
                                    return [];
                                }

                                return app(RoleAssignmentService::class)
                                    ->getAssignableStoreRoleOptions($authUser, $store);
                            }),
                    ])
                    ->action(function (array $data) {
                        $store = filament()->getTenant();
                        $invitedBy = Auth::user();
                        $assignableRoleIds = [];
                        $roleId = $data['role_id'] ?? null;

                        if ($roleId !== null && ! $this->canAssignRoles()) {
                            Notification::make()
                                ->title('Unauthorized')
                                ->body('You do not have permission to assign roles.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($invitedBy instanceof User && $store) {
                            $assignableRoleIds = app(RoleAssignmentService::class)
                                ->getAssignableStoreRoleIds($invitedBy, $store);
                        }

                        if ($roleId !== null) {
                            $roleId = (int) $roleId;
                        }

                        if ($roleId !== null && ! in_array($roleId, $assignableRoleIds, true)) {
                            Notification::make()
                                ->title('Unauthorized Role Assignment')
                                ->body('You can only assign roles that are explicitly granted to you.')
                                ->danger()
                                ->send();

                            return;
                        }

                        // Check for recent invitation to same email (throttling)
                        $recentInvitation = Invitation::where('email', $data['email'])
                            ->where('store_id', $store->id)
                            ->where('created_at', '>', now()->subMinutes(60))
                            ->first();

                        if ($recentInvitation) {
                            Notification::make()
                                ->title('Invitation Already Sent')
                                ->body('An invitation was recently sent to this email. Please wait 60 minutes before sending another invitation.')
                                ->warning()
                                ->send();

                            return;
                        }

                        // Check if user already exists
                        $existingUser = \App\Models\User::where('email', $data['email'])->first();

                        if ($existingUser) {
                            // Check if user is already in this store
                            if ($existingUser->stores()->where('store_id', $store->id)->exists()) {
                                Notification::make()
                                    ->title('User Already Exists')
                                    ->body('This user is already a member of this store.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Add existing user to store with role
                            $existingUser->stores()->attach($store->id, [
                                'role_id' => $data['role_id'] ?? null,
                            ]);

                            // Attach role in user_role table for permission checks
                            if (! empty($data['role_id'])) {
                                if (! $existingUser->roles()->wherePivot('store_id', $store->id)->where('roles.id', $data['role_id'])->exists()) {
                                    $existingUser->roles()->attach($data['role_id'], [
                                        'store_id' => $store->id,
                                    ]);
                                }
                            }

                            Notification::make()
                                ->title('User Added Successfully')
                                ->body('The user has been added to the store.')
                                ->success()
                                ->send();
                        } else {
                            // Create invitation
                            $invitation = Invitation::createInvitation(
                                $data['email'],
                                $store,
                                $invitedBy,
                                $data['role_id'] ?? null
                            );

                            $invitation->notify(new \SmartTill\Core\Notifications\StoreInvitationNotification($invitation));

                            Notification::make()
                                ->title('Invitation Sent')
                                ->body('An invitation has been sent to '.$data['email'].' to join the store.')
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('resend_invitation')
                        ->label('Resend Invite')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->color('warning')
                        ->visible(fn ($record) => $this->canResend() && ! $record->isAccepted() && ! $record->isExpired())
                        ->authorize(fn ($record) => $this->canResend() && ! $record->isAccepted() && ! $record->isExpired())
                        ->action(function ($record) {
                            $store = filament()->getTenant();
                            $invitedBy = Auth::user();

                            // Check throttling for resend
                            if (! $this->canResendInvitation($record)) {
                                $lastSentAt = $record->updated_at ?? $record->created_at;
                                $canResendAt = $lastSentAt->addMinutes(60);
                                $waitTime = now()->diff($canResendAt);

                                $minutes = $waitTime->i;
                                $seconds = $waitTime->s;

                                $timeString = $minutes > 0
                                    ? ($seconds > 0 ? "{$minutes} minutes and {$seconds} seconds" : "{$minutes} minutes")
                                    : "{$seconds} seconds";

                                Notification::make()
                                    ->title('Please Wait')
                                    ->body("You can resend this invitation in {$timeString}.")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Update existing invitation
                            $record->update([
                                'expires_at' => now()->addDays(7),
                            ]);

                            $record->notify(new \SmartTill\Core\Notifications\StoreInvitationNotification($record));

                            Notification::make()
                                ->title('Invitation Updated')
                                ->body('Invitation has been updated and resent to '.$record->email)
                                ->success()
                                ->send();
                        }),

                    Action::make('copy_link')
                        ->label('Copy Invite Link')
                        ->icon(Heroicon::OutlinedClipboard)
                        ->color('gray')
                        ->visible(fn ($record) => $this->canViewInvitations() && ! $record->isAccepted())
                        ->authorize(fn ($record) => $this->canViewInvitations() && ! $record->isAccepted())
                        ->action(function ($record) {
                            $registrationUrl = route('filament.store.auth.register', [
                                'tenant' => $record->store->slug,
                                'token' => $record->token,
                                'email' => $record->email,
                            ]);

                            // Copy to clipboard using JavaScript
                            $this->js("
                                navigator.clipboard.writeText('{$registrationUrl}').then(function() {
                                    \$wire.dispatch('notify', {
                                        title: 'Link Copied',
                                        body: 'Invitation link has been copied to clipboard',
                                        type: 'success'
                                    });
                                }).catch(function() {
                                    \$wire.dispatch('notify', {
                                        title: 'Copy Failed',
                                        body: 'Could not copy link to clipboard',
                                        type: 'error'
                                    });
                                });
                            ");
                        }),

                    Action::make('cancel_invitation')
                        ->label('Cancel Invite')
                        ->icon(Heroicon::OutlinedXMark)
                        ->color('danger')
                        ->visible(fn ($record) => $this->canCancel() && ! $record->isAccepted())
                        ->authorize(fn ($record) => $this->canCancel() && ! $record->isAccepted())
                        ->requiresConfirmation()
                        ->modalHeading('Cancel Invitation')
                        ->modalDescription('Are you sure you want to cancel this invitation? This action cannot be undone.')
                        ->modalSubmitActionLabel('Yes, Cancel Invitation')
                        ->action(function ($record) {
                            $record->delete(); // Delete the invitation from database

                            Notification::make()
                                ->title('Invitation Cancelled')
                                ->body('The invitation has been cancelled and removed.')
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    private function canInvite(): bool
    {
        if (! $this->canViewInvitations()) {
            return false;
        }

        $user = Auth::user();
        $store = filament()->getTenant();

        if (! $user || ! $store) {
            return false;
        }

        // Check Super Admin first
        $hasSuperAdmin = \Illuminate\Support\Facades\DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->where('user_role.user_id', $user->id)
            ->where('user_role.store_id', $store->id)
            ->where('roles.panel', 'store')
            ->where('roles.is_system', true)
            ->whereNull('roles.store_id')
            ->where('roles.name', 'Super Admin')
            ->exists();

        if ($hasSuperAdmin) {
            return true;
        }

        return $user->roles()
            ->wherePivot('store_id', $store->id)
            ->where('panel', 'store')
            ->whereHas('permissions', function ($query) {
                $query->where('name', 'Invite Users')->where('panel', 'store');
            })
            ->exists();
    }

    private function canResend(): bool
    {
        if (! $this->canViewInvitations()) {
            return false;
        }

        $user = Auth::user();
        $store = filament()->getTenant();

        if (! $user || ! $store) {
            return false;
        }

        // Check Super Admin first
        $hasSuperAdmin = \Illuminate\Support\Facades\DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->where('user_role.user_id', $user->id)
            ->where('user_role.store_id', $store->id)
            ->where('roles.panel', 'store')
            ->where('roles.is_system', true)
            ->whereNull('roles.store_id')
            ->where('roles.name', 'Super Admin')
            ->exists();

        if ($hasSuperAdmin) {
            return true;
        }

        return $user->roles()
            ->wherePivot('store_id', $store->id)
            ->where('panel', 'store')
            ->whereHas('permissions', function ($query) {
                $query->where('name', 'Resend Invitations')->where('panel', 'store');
            })
            ->exists();
    }

    private function canCancel(): bool
    {
        if (! $this->canViewInvitations()) {
            return false;
        }

        $user = Auth::user();
        $store = filament()->getTenant();

        if (! $user || ! $store) {
            return false;
        }

        // Check Super Admin first
        $hasSuperAdmin = \Illuminate\Support\Facades\DB::table('user_role')
            ->join('roles', 'user_role.role_id', '=', 'roles.id')
            ->where('user_role.user_id', $user->id)
            ->where('user_role.store_id', $store->id)
            ->where('roles.panel', 'store')
            ->where('roles.is_system', true)
            ->whereNull('roles.store_id')
            ->where('roles.name', 'Super Admin')
            ->exists();

        if ($hasSuperAdmin) {
            return true;
        }

        return $user->roles()
            ->wherePivot('store_id', $store->id)
            ->where('panel', 'store')
            ->whereHas('permissions', function ($query) {
                $query->where('name', 'Cancel Invitations')->where('panel', 'store');
            })
            ->exists();
    }

    private function canResendInvitation($record): bool
    {
        // Check if invitation was sent less than 60 minutes ago
        $lastSentAt = $record->updated_at ?? $record->created_at;
        $canResendAt = $lastSentAt->addMinutes(60);

        return now()->isAfter($canResendAt);
    }

    private function canViewInvitations(): bool
    {
        return ResourceCanAccessHelper::check('View Invitations');
    }

    private function canAssignRoles(): bool
    {
        return ResourceCanAccessHelper::check('View Roles');
    }
}
