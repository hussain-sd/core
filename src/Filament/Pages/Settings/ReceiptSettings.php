<?php

namespace SmartTill\Core\Filament\Pages\Settings;

use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use SmartTill\Core\Enums\PrintOption;
use SmartTill\Core\Filament\Resources\Helpers\ResourceCanAccessHelper;
use SmartTill\Core\Services\CoreStoreSettingsService;

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
        $settingsService = app(CoreStoreSettingsService::class);

        $this->form->fill([
            'receipt_format' => $settingsService->getReceiptFormat($store),
            'show_decimals_in_total' => $settingsService->getShowDecimalsInReceiptTotal($store),
            'show_differences' => $settingsService->getShowDifferencesInReceipt($store),
            'show_header_note' => $settingsService->getShowHeaderNoteInReceipt($store),
            'show_footer_note' => $settingsService->getShowFooterNoteInReceipt($store),
            'header_note_label' => $settingsService->getHeaderNoteLabel($store),
            'footer_note_label' => $settingsService->getFooterNoteLabel($store),
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
                Section::make('Notes')
                    ->schema([
                        Toggle::make('show_header_note')
                            ->label('Show Header Note'),
                        TextInput::make('header_note_label')
                            ->label('Header Note Label')
                            ->maxLength(50)
                            ->required()
                            ->default('Header Note'),
                        Toggle::make('show_footer_note')
                            ->label('Show Footer Note'),
                        TextInput::make('footer_note_label')
                            ->label('Footer Note Label')
                            ->maxLength(50)
                            ->required()
                            ->default('Footer Note'),
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
        $settingsService = app(CoreStoreSettingsService::class);

        $settingsService->setSetting($store, CoreStoreSettingsService::SETTING_RECEIPT_FORMAT, $data['receipt_format'], 'dropdown');
        $settingsService->setSetting($store, CoreStoreSettingsService::SETTING_RECEIPT_SHOW_DECIMALS_IN_TOTAL, (bool) $data['show_decimals_in_total'], 'dropdown');
        $settingsService->setSetting($store, CoreStoreSettingsService::SETTING_RECEIPT_SHOW_DIFFERENCES, (bool) ($data['show_differences'] ?? false), 'dropdown');
        $settingsService->setSetting($store, CoreStoreSettingsService::SETTING_RECEIPT_SHOW_HEADER_NOTE, (bool) ($data['show_header_note'] ?? true), 'dropdown');
        $settingsService->setSetting($store, CoreStoreSettingsService::SETTING_RECEIPT_SHOW_FOOTER_NOTE, (bool) ($data['show_footer_note'] ?? true), 'dropdown');
        $settingsService->setSetting($store, CoreStoreSettingsService::SETTING_RECEIPT_HEADER_NOTE_LABEL, $data['header_note_label'] ?? 'Header Note', 'string');
        $settingsService->setSetting($store, CoreStoreSettingsService::SETTING_RECEIPT_FOOTER_NOTE_LABEL, $data['footer_note_label'] ?? 'Footer Note', 'string');

        Notification::make()
            ->title('Receipt settings saved')
            ->success()
            ->send();
    }
}
