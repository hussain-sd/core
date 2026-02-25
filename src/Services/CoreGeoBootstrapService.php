<?php

namespace SmartTill\Core\Services;

use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use PragmaRX\Countries\Package\Countries;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Timezone;
use Throwable;

class CoreGeoBootstrapService
{
    /**
     * UN-recognized sovereign states (195).
     */
    private const SOVEREIGN_COUNTRIES = [
        'AF', 'AL', 'DZ', 'AD', 'AO', 'AG', 'AR', 'AM', 'AU', 'AT', 'AZ', 'BS', 'BH', 'BD', 'BB', 'BY', 'BE', 'BZ', 'BJ', 'BT', 'BO', 'BA', 'BW', 'BR', 'BN', 'BG', 'BF', 'BI', 'CV', 'KH', 'CM', 'CA', 'CF', 'TD', 'CL', 'CN', 'CO', 'KM', 'CG', 'CD', 'CR', 'CI', 'HR', 'CU', 'CY', 'CZ', 'DK', 'DJ', 'DM', 'DO', 'EC', 'EG', 'SV', 'GQ', 'ER', 'EE', 'SZ', 'ET', 'FJ', 'FI', 'FR', 'GA', 'GM', 'GE', 'DE', 'GH', 'GR', 'GD', 'GT', 'GN', 'GW', 'GY', 'HT', 'HN', 'HU', 'IS', 'IN', 'ID', 'IR', 'IQ', 'IE', 'IL', 'IT', 'JM', 'JP', 'JO', 'KZ', 'KE', 'KI', 'KP', 'KR', 'KW', 'KG', 'LA', 'LV', 'LB', 'LS', 'LR', 'LY', 'LI', 'LT', 'LU', 'MG', 'MW', 'MY', 'MV', 'ML', 'MT', 'MH', 'MR', 'MU', 'MX', 'FM', 'MD', 'MC', 'MN', 'ME', 'MA', 'MZ', 'MM', 'NA', 'NR', 'NP', 'NL', 'NZ', 'NI', 'NE', 'NG', 'MK', 'NO', 'OM', 'PK', 'PW', 'PA', 'PG', 'PY', 'PE', 'PH', 'PL', 'PT', 'QA', 'RO', 'RU', 'RW', 'KN', 'LC', 'VC', 'WS', 'SM', 'ST', 'SA', 'SN', 'RS', 'SC', 'SL', 'SG', 'SK', 'SI', 'SB', 'SO', 'ZA', 'SS', 'ES', 'LK', 'SD', 'SR', 'SE', 'CH', 'SY', 'TJ', 'TZ', 'TH', 'TL', 'TG', 'TO', 'TT', 'TN', 'TR', 'TM', 'TV', 'UG', 'UA', 'AE', 'GB', 'US', 'UY', 'UZ', 'VU', 'VA', 'VE', 'VN', 'YE', 'ZM', 'ZW',
    ];

    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    private const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public function ensureGeoData(?string $connection = null): void
    {
        $this->syncCountries($connection);
        $this->syncCurrencies($connection);
        $this->syncTimezones($connection);
    }

    private function syncCountries(?string $connection): void
    {
        $iso3166 = new \League\ISO3166\ISO3166;
        $countryQuery = $connection ? Country::on($connection) : Country::query();

        foreach ($iso3166->all() as $country) {
            $alpha2 = $country['alpha2'] ?? null;
            if (! is_string($alpha2) || ! in_array($alpha2, self::SOVEREIGN_COUNTRIES, true)) {
                continue;
            }

            $countryQuery->updateOrCreate(
                ['code' => $alpha2],
                [
                    'name' => $country['name'] ?? $alpha2,
                    'code3' => $country['alpha3'] ?? null,
                    'numeric_code' => $country['numeric'] ?? null,
                    'synced_at' => now(),
                ]
            );
        }
    }

    private function syncCurrencies(?string $connection): void
    {
        $countries = new Countries;
        $currencyRowsByCode = [];
        $countryCurrencyCodes = [];

        foreach ($countries->all() as $country) {
            $countryCode = is_string($country->cca2 ?? null) ? $country->cca2 : null;
            if (! $countryCode || ! in_array($countryCode, self::SOVEREIGN_COUNTRIES, true)) {
                continue;
            }

            $currencies = $country->currencies ?? [];
            $currenciesArray = is_array($currencies) ? $currencies : (method_exists($currencies, 'toArray') ? $currencies->toArray() : []);

            foreach ($currenciesArray as $key => $value) {
                $currencyCode = null;
                $currencyName = null;

                if (is_array($value) && is_string($key) && strlen($key) === 3) {
                    $currencyCode = strtoupper($key);
                    $currencyName = is_string($value['name'] ?? null) ? $value['name'] : null;
                } elseif (is_string($value) && strlen($value) === 3) {
                    $currencyCode = strtoupper($value);
                }

                if (! $currencyCode || ! preg_match('/^[A-Z]{3}$/', $currencyCode)) {
                    continue;
                }

                if (! isset($currencyRowsByCode[$currencyCode])) {
                    $currencyRowsByCode[$currencyCode] = [
                        'name' => $currencyName ?? "{$currencyCode} Currency",
                        'code' => $currencyCode,
                        'decimal_places' => $this->resolveDecimalPlaces($currencyCode),
                    ];
                } elseif (($currencyRowsByCode[$currencyCode]['name'] ?? null) === "{$currencyCode} Currency" && $currencyName) {
                    $currencyRowsByCode[$currencyCode]['name'] = $currencyName;
                }

                $countryCurrencyCodes[$countryCode] ??= [];
                if (! in_array($currencyCode, $countryCurrencyCodes[$countryCode], true)) {
                    $countryCurrencyCodes[$countryCode][] = $currencyCode;
                }
            }
        }

        $currencyQuery = $connection ? Currency::on($connection) : Currency::query();
        foreach ($currencyRowsByCode as $row) {
            $currencyQuery->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'decimal_places' => $row['decimal_places'],
                ]
            );
        }

        $countryQuery = $connection ? Country::on($connection) : Country::query();
        $countryIdsByCode = $countryQuery->pluck('id', 'code')->all();
        $currencyIdsByCode = $currencyQuery->pluck('id', 'code')->all();

        $pivotRows = [];
        foreach ($countryCurrencyCodes as $countryCode => $currencyCodes) {
            $countryId = $countryIdsByCode[$countryCode] ?? null;
            if (! $countryId) {
                continue;
            }

            foreach ($currencyCodes as $currencyCode) {
                $currencyId = $currencyIdsByCode[$currencyCode] ?? null;
                if (! $currencyId) {
                    continue;
                }

                $pivotRows[] = [
                    'country_id' => $countryId,
                    'currency_id' => $currencyId,
                ];
            }
        }

        $db = DB::connection($connection);
        $db->table('country_currency')->delete();
        if (! empty($pivotRows)) {
            $db->table('country_currency')->insert($pivotRows);
        }
    }

    private function syncTimezones(?string $connection): void
    {
        $countryQuery = $connection ? Country::on($connection) : Country::query();
        $countryIdsByCode = $countryQuery->pluck('id', 'code')->all();
        $timezoneQuery = $connection ? Timezone::on($connection) : Timezone::query();

        $timezoneNames = [];
        $countryTimezoneNames = [];

        foreach ($countryIdsByCode as $countryCode => $countryId) {
            try {
                $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $countryCode);
            } catch (Throwable) {
                $identifiers = [];
            }

            if (! is_array($identifiers)) {
                $identifiers = [];
            }

            foreach ($identifiers as $timezoneName) {
                if (! is_string($timezoneName) || $timezoneName === '') {
                    continue;
                }

                $timezoneNames[$timezoneName] = true;
                $countryTimezoneNames[$countryCode] ??= [];
                if (! in_array($timezoneName, $countryTimezoneNames[$countryCode], true)) {
                    $countryTimezoneNames[$countryCode][] = $timezoneName;
                }
            }
        }

        foreach (array_keys($timezoneNames) as $timezoneName) {
            $timezoneQuery->updateOrCreate(
                ['name' => $timezoneName],
                ['offset' => $this->resolveOffset($timezoneName)]
            );
        }

        $timezoneIdsByName = $timezoneQuery->pluck('id', 'name')->all();
        $pivotRows = [];
        foreach ($countryTimezoneNames as $countryCode => $timezoneNamesForCountry) {
            $countryId = $countryIdsByCode[$countryCode] ?? null;
            if (! $countryId) {
                continue;
            }

            foreach ($timezoneNamesForCountry as $timezoneName) {
                $timezoneId = $timezoneIdsByName[$timezoneName] ?? null;
                if (! $timezoneId) {
                    continue;
                }

                $pivotRows[] = [
                    'country_id' => $countryId,
                    'timezone_id' => $timezoneId,
                ];
            }
        }

        $db = DB::connection($connection);
        $db->table('country_timezone')->delete();
        if (! empty($pivotRows)) {
            $db->table('country_timezone')->insert($pivotRows);
        }
    }

    private function resolveDecimalPlaces(string $currencyCode): int
    {
        if (in_array($currencyCode, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return 0;
        }

        if (in_array($currencyCode, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        return 2;
    }

    private function resolveOffset(string $timezoneName): string
    {
        try {
            $timezone = new DateTimeZone($timezoneName);
            $offsetSeconds = $timezone->getOffset(new DateTime('now', new DateTimeZone('UTC')));
        } catch (Throwable) {
            return '+00:00';
        }

        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $offsetSeconds = abs($offsetSeconds);
        $hours = intdiv($offsetSeconds, 3600);
        $minutes = intdiv($offsetSeconds % 3600, 60);

        return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
    }
}

