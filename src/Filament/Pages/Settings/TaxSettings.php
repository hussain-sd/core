<?php

namespace SmartTill\Core\Filament\Pages\Settings;

use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;

class TaxSettings extends Page
{
    use InteractsWithForms;

    protected string $view = 'smart-core::filament.pages.settings.tax-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Tax Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

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
            'tax_enabled' => $store->tax_enabled,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Tax Settings')
                    ->schema([
                        Toggle::make('tax_enabled')
                            ->label('Enable taxes'),
                    ]),
            ]);
    }

    public function save(): void
    {
        if (! ResourceCanAccessHelper::check('Edit Store Settings')) {
            abort(403);
        }

        /** @var Store $store */
        $store = Filament::getTenant();
        $data = $this->form->getState();

        $store->setSetting(Store::SETTING_TAX_ENABLED, (bool) $data['tax_enabled'], 'dropdown');

        Notification::make()
            ->title('Tax settings saved')
            ->success()
            ->send();
    }
}
