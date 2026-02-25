<?php

namespace SmartTill\Core\Filament\Pages\Settings;

use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use SmartTill\Core\Enums\PrintOption;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;

class ReceiptSettings extends Page
{
    use InteractsWithForms;

    protected string $view = 'smart-core::filament.pages.settings.receipt-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?string $navigationLabel = 'Receipt Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

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
            'receipt_format' => $store->default_print_option->value,
            'show_decimals_in_total' => $store->show_decimals_in_receipt_total,
            'show_differences' => $store->show_differences_in_receipt,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Receipt Settings')
                    ->schema([
                        Select::make('receipt_format')
                            ->label('Receipt Format')
                            ->options(collect(PrintOption::cases())->mapWithKeys(fn (PrintOption $option): array => [$option->value => $option->getLabel()]))
                            ->required(),
                        Toggle::make('show_decimals_in_total')
                            ->label('Show decimals in receipt total')
                            ->live(),
                        Toggle::make('show_differences')
                            ->label('Show differences in receipt')
                            ->visible(fn (callable $get): bool => ! $get('show_decimals_in_total')),
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

        $store->setSetting(Store::SETTING_RECEIPT_FORMAT, $data['receipt_format'], 'dropdown');
        $store->setSetting(Store::SETTING_RECEIPT_SHOW_DECIMALS_IN_TOTAL, (bool) $data['show_decimals_in_total'], 'dropdown');
        $store->setSetting(Store::SETTING_RECEIPT_SHOW_DIFFERENCES, (bool) $data['show_differences'], 'dropdown');

        Notification::make()
            ->title('Receipt settings saved')
            ->success()
            ->send();
    }
}
