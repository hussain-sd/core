<?php

namespace SmartTill\Core\Services;

use App\Models\Store as AppStore;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use SmartTill\Core\Enums\FbrEnvironment;
use SmartTill\Core\Enums\PrintOption;
use SmartTill\Core\Models\Stock;
use SmartTill\Core\Models\StoreSetting;

class CoreStoreSettingsService
{
    public const SETTING_RECEIPT_FORMAT = 'RECEIPT_FORMAT';

    public const SETTING_RECEIPT_SHOW_DECIMALS_IN_TOTAL = 'RECEIPT_SHOW_DECIMALS_IN_TOTAL';

    public const SETTING_RECEIPT_SHOW_DIFFERENCES = 'RECEIPT_SHOW_DIFFERENCES';

    public const SETTING_RECEIPT_SHOW_HEADER_NOTE = 'RECEIPT_SHOW_HEADER_NOTE';

    public const SETTING_RECEIPT_SHOW_FOOTER_NOTE = 'RECEIPT_SHOW_FOOTER_NOTE';

    public const SETTING_RECEIPT_HEADER_NOTE_LABEL = 'RECEIPT_HEADER_NOTE_LABEL';

    public const SETTING_RECEIPT_FOOTER_NOTE_LABEL = 'RECEIPT_FOOTER_NOTE_LABEL';

    public const SETTING_TAX_ENABLED = 'TAX_ENABLED';

    public const SETTING_FBR_ENVIRONMENT = 'FBR_ENVIRONMENT';

    public const SETTING_FBR_SANDBOX_POS_ID = 'FBR_SANDBOX_POS_ID';

    public const SETTING_FBR_POS_ID = 'FBR_POS_ID';

    public const SETTING_FBR_BEARER_TOKEN = 'FBR_BEARER_TOKEN';

    public function ensureDefaultsForAllStores(?string $connection = null): void
    {
        if (! class_exists(AppStore::class)) {
            return;
        }

        $query = $connection ? AppStore::on($connection) : AppStore::query();

        $query->chunkById(100, function ($stores): void {
            foreach ($stores as $store) {
                if ($store instanceof Model) {
                    $this->initializeDefaultSettings($store);
                }
            }
        });
    }

    public function initializeDefaultSettings(Model $store): void
    {
        $storeId = (int) $store->getAttribute('id');
        if ($storeId <= 0) {
            return;
        }

        $rows = [];
        $now = now();

        foreach ($this->settingsBlueprint($store) as $key => $config) {
            $rows[] = [
                'store_id' => $storeId,
                'key' => $key,
                'value' => $config['value'],
                'type' => $config['type'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        StoreSetting::query()->upsert(
            $rows,
            ['store_id', 'key'],
            ['value', 'type', 'updated_at']
        );
    }

    public function setSetting(Model $store, string $key, string|int|bool|null $value, ?string $type = null): void
    {
        $storeId = (int) $store->getAttribute('id');
        if ($storeId <= 0) {
            return;
        }

        StoreSetting::query()->updateOrCreate(
            [
                'store_id' => $storeId,
                'key' => $key,
            ],
            [
                'value' => match (true) {
                    is_bool($value) => $value ? '1' : '0',
                    is_null($value) => null,
                    default => (string) $value,
                },
                'type' => $type ?? $this->resolveSettingType($key),
            ]
        );
    }

    public function getEffectiveTaxAmount(?Model $store, ?Stock $stock, float $basePrice): float
    {
        if (! $store || ! $this->isTaxEnabled($store)) {
            return 0.0;
        }

        $taxPercentage = (float) ($stock?->tax_percentage ?? 0);

        return round($basePrice * ($taxPercentage / 100), 2);
    }

    public function isTaxEnabled(?Model $store): bool
    {
        if (! $store) {
            return false;
        }

        return $this->getBooleanSetting($store, self::SETTING_TAX_ENABLED, (bool) ($store->getAttribute('tax_enabled') ?? false));
    }

    public function getReceiptFormat(Model $store): string
    {
        $value = $this->getSettingValue($store, self::SETTING_RECEIPT_FORMAT);
        $fallback = $this->stringify($store->getAttribute('default_print_option'));
        $resolved = $value ?? ($fallback ?? PrintOption::default()->value);

        return PrintOption::tryFrom($resolved)?->value ?? PrintOption::default()->value;
    }

    public function getShowDecimalsInReceiptTotal(Model $store): bool
    {
        return $this->getBooleanSetting(
            $store,
            self::SETTING_RECEIPT_SHOW_DECIMALS_IN_TOTAL,
            (bool) ($store->getAttribute('show_decimals_in_receipt_total') ?? true)
        );
    }

    public function getShowDifferencesInReceipt(Model $store): bool
    {
        return $this->getBooleanSetting(
            $store,
            self::SETTING_RECEIPT_SHOW_DIFFERENCES,
            (bool) ($store->getAttribute('show_differences_in_receipt') ?? false)
        );
    }

    public function getShowHeaderNoteInReceipt(Model $store): bool
    {
        return $this->getBooleanSetting(
            $store,
            self::SETTING_RECEIPT_SHOW_HEADER_NOTE,
            true
        );
    }

    public function getShowFooterNoteInReceipt(Model $store): bool
    {
        return $this->getBooleanSetting(
            $store,
            self::SETTING_RECEIPT_SHOW_FOOTER_NOTE,
            true
        );
    }

    public function getHeaderNoteLabel(Model $store): string
    {
        return $this->getSettingValue($store, self::SETTING_RECEIPT_HEADER_NOTE_LABEL)
            ?? 'Header Note';
    }

    public function getFooterNoteLabel(Model $store): string
    {
        return $this->getSettingValue($store, self::SETTING_RECEIPT_FOOTER_NOTE_LABEL)
            ?? 'Footer Note';
    }

    public function getFbrEnvironment(Model $store): string
    {
        $value = $this->getSettingValue($store, self::SETTING_FBR_ENVIRONMENT);
        $fallback = $this->stringify($store->getAttribute('fbr_environment'));
        $resolved = $value ?? ($fallback ?? FbrEnvironment::SANDBOX->value);

        return FbrEnvironment::tryFrom($resolved)?->value ?? FbrEnvironment::SANDBOX->value;
    }

    public function getFbrSandboxPosId(Model $store): ?int
    {
        $value = $this->getSettingValue($store, self::SETTING_FBR_SANDBOX_POS_ID);
        if ($value === null || $value === '') {
            $fallback = $store->getAttribute('fbr_sandbox_pos_id');

            return is_numeric($fallback) ? (int) $fallback : null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function getFbrPosId(Model $store): ?int
    {
        $value = $this->getSettingValue($store, self::SETTING_FBR_POS_ID);
        if ($value === null || $value === '') {
            $fallback = $store->getAttribute('fbr_pos_id');

            return is_numeric($fallback) ? (int) $fallback : null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function getFbrBearerToken(Model $store): ?string
    {
        return $this->getSettingValue($store, self::SETTING_FBR_BEARER_TOKEN)
            ?? (($store->getAttribute('fbr_bearer_token') ?: null) ? (string) $store->getAttribute('fbr_bearer_token') : null);
    }

    private function getBooleanSetting(Model $store, string $key, bool $fallback): bool
    {
        $value = $this->getSettingValue($store, $key);

        if ($value === null) {
            return $fallback;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function getSettingValue(Model $store, string $key): ?string
    {
        $storeId = (int) $store->getAttribute('id');
        if ($storeId <= 0) {
            return null;
        }

        if ($store->relationLoaded('settings')) {
            return $store->getRelation('settings')->firstWhere('key', $key)?->value;
        }

        return StoreSetting::query()
            ->where('store_id', $storeId)
            ->where('key', $key)
            ->value('value');
    }

    private function resolveSettingType(string $key): string
    {
        return match ($key) {
            self::SETTING_FBR_SANDBOX_POS_ID, self::SETTING_FBR_POS_ID => 'number',
            self::SETTING_RECEIPT_HEADER_NOTE_LABEL, self::SETTING_RECEIPT_FOOTER_NOTE_LABEL => 'string',
            self::SETTING_FBR_BEARER_TOKEN => 'text_area',
            default => 'dropdown',
        };
    }

    private function settingsBlueprint(Model $store): array
    {
        $defaultPrintOption = $this->stringify($store->getAttribute('default_print_option')) ?? PrintOption::default()->value;
        $fbrEnvironment = $this->stringify($store->getAttribute('fbr_environment')) ?? FbrEnvironment::SANDBOX->value;

        return [
            self::SETTING_RECEIPT_FORMAT => [
                'value' => $defaultPrintOption,
                'type' => 'dropdown',
            ],
            self::SETTING_RECEIPT_SHOW_DECIMALS_IN_TOTAL => [
                'value' => ((bool) ($store->getAttribute('show_decimals_in_receipt_total') ?? true)) ? '1' : '0',
                'type' => 'dropdown',
            ],
            self::SETTING_RECEIPT_SHOW_DIFFERENCES => [
                'value' => ((bool) ($store->getAttribute('show_differences_in_receipt') ?? false)) ? '1' : '0',
                'type' => 'dropdown',
            ],
            self::SETTING_RECEIPT_SHOW_HEADER_NOTE => [
                'value' => '1',
                'type' => 'dropdown',
            ],
            self::SETTING_RECEIPT_SHOW_FOOTER_NOTE => [
                'value' => '1',
                'type' => 'dropdown',
            ],
            self::SETTING_RECEIPT_HEADER_NOTE_LABEL => [
                'value' => 'Header Note',
                'type' => 'string',
            ],
            self::SETTING_RECEIPT_FOOTER_NOTE_LABEL => [
                'value' => 'Footer Note',
                'type' => 'string',
            ],
            self::SETTING_TAX_ENABLED => [
                'value' => ((bool) ($store->getAttribute('tax_enabled') ?? false)) ? '1' : '0',
                'type' => 'dropdown',
            ],
            self::SETTING_FBR_ENVIRONMENT => [
                'value' => $fbrEnvironment,
                'type' => 'dropdown',
            ],
            self::SETTING_FBR_SANDBOX_POS_ID => [
                'value' => isset($store->getAttributes()['fbr_sandbox_pos_id']) ? (string) $store->getAttribute('fbr_sandbox_pos_id') : null,
                'type' => 'number',
            ],
            self::SETTING_FBR_POS_ID => [
                'value' => isset($store->getAttributes()['fbr_pos_id']) ? (string) $store->getAttribute('fbr_pos_id') : null,
                'type' => 'number',
            ],
            self::SETTING_FBR_BEARER_TOKEN => [
                'value' => $store->getAttribute('fbr_bearer_token') ? (string) $store->getAttribute('fbr_bearer_token') : null,
                'type' => 'text_area',
            ],
        ];
    }

    private function stringify(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
