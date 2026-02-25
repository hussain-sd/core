<?php

namespace SmartTill\Core\Filament\Pages\Settings;

use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use SmartTill\Core\Enums\FbrEnvironment;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;

class FbrSettings extends Page
{
    use InteractsWithForms;

    protected string $view = 'smart-core::filament.pages.settings.fbr-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'FBR Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public bool $canEdit = false;

    public static function canAccess(): bool
    {
        return ResourceCanAccessHelper::check('View Store Settings');
    }

    public function mount(): void
    {
        $this->canEdit = ResourceCanAccessHelper::check('Edit Store Settings');

        /** @var Store $store */
        $store = Filament::getTenant();

        $this->form->fill([
            'fbr_environment' => $store->fbr_environment->value,
            'fbr_sandbox_pos_id' => $store->fbr_sandbox_pos_id,
            'fbr_pos_id' => $store->fbr_pos_id,
            'fbr_bearer_token' => $store->fbr_bearer_token,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('FBR Integration')
                    ->description('FBR integration is available for Pakistan stores only.')
                    ->schema([
                        Select::make('fbr_environment')
                            ->label('Environment')
                            ->options(collect(FbrEnvironment::cases())->mapWithKeys(fn (FbrEnvironment $option): array => [$option->value => $option->getLabel()]))
                            ->required(),
                        TextInput::make('fbr_sandbox_pos_id')
                            ->label('POS ID (Sandbox)')
                            ->numeric(),
                        TextInput::make('fbr_pos_id')
                            ->label('POS ID (Production)')
                            ->numeric(),
                        TextInput::make('fbr_bearer_token')
                            ->label('Bearer Token')
                            ->password()
                            ->revealable(),
                    ])
                    ->disabled(fn (): bool => ! (Filament::getTenant()?->isPakistan() ?? false)),
            ]);
    }

    public function save(): void
    {
        if (! ResourceCanAccessHelper::check('Edit Store Settings')) {
            abort(403);
        }

        /** @var Store $store */
        $store = Filament::getTenant();

        if (! $store->isPakistan()) {
            Notification::make()
                ->title('FBR settings are only available for Pakistan stores')
                ->warning()
                ->send();

            return;
        }

        $data = $this->form->getState();

        $store->setSetting(Store::SETTING_FBR_ENVIRONMENT, $data['fbr_environment'], 'dropdown');
        $store->setSetting(Store::SETTING_FBR_SANDBOX_POS_ID, $data['fbr_sandbox_pos_id'] ?? null, 'number');
        $store->setSetting(Store::SETTING_FBR_POS_ID, $data['fbr_pos_id'] ?? null, 'number');
        $store->setSetting(Store::SETTING_FBR_BEARER_TOKEN, $data['fbr_bearer_token'] ?? null, 'text_area');

        Notification::make()
            ->title('FBR settings saved')
            ->success()
            ->send();
    }
}
