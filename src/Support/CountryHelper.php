<?php

namespace Lumina\Core\Support;

class CountryHelper
{
    /**
     * ISO 3166-1 alpha-2 country codes to full English country names.
     *
     * @var array<string, string>
     */
    protected static array $countries = [
        'AF' => 'Afghanistan',
        'AL' => 'Albania',
        'DZ' => 'Algeria',
        'AR' => 'Argentina',
        'AM' => 'Armenia',
        'AU' => 'Australia',
        'AT' => 'Austria',
        'AZ' => 'Azerbaijan',
        'BD' => 'Bangladesh',
        'BE' => 'Belgium',
        'BR' => 'Brazil',
        'BG' => 'Bulgaria',
        'CA' => 'Canada',
        'CL' => 'Chile',
        'CN' => 'China',
        'CO' => 'Colombia',
        'HR' => 'Croatia',
        'CZ' => 'Czech Republic',
        'DK' => 'Denmark',
        'EG' => 'Egypt',
        'FI' => 'Finland',
        'FR' => 'France',
        'DE' => 'Germany',
        'GR' => 'Greece',
        'HK' => 'Hong Kong',
        'HU' => 'Hungary',
        'IN' => 'India',
        'ID' => 'Indonesia',
        'IE' => 'Ireland',
        'IL' => 'Israel',
        'IT' => 'Italy',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'MY' => 'Malaysia',
        'MX' => 'Mexico',
        'NL' => 'Netherlands',
        'NZ' => 'New Zealand',
        'NO' => 'Norway',
        'PK' => 'Pakistan',
        'PH' => 'Philippines',
        'PL' => 'Poland',
        'PT' => 'Portugal',
        'RO' => 'Romania',
        'RU' => 'Russia',
        'SA' => 'Saudi Arabia',
        'SG' => 'Singapore',
        'ZA' => 'South Africa',
        'ES' => 'Spain',
        'SE' => 'Sweden',
        'CH' => 'Switzerland',
        'TW' => 'Taiwan',
        'TH' => 'Thailand',
        'TR' => 'Turkey',
        'UA' => 'Ukraine',
        'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'VN' => 'Vietnam',
    ];

    /**
     * Get English country name from 2-letter ISO country code.
     */
    public static function getName(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        $code = strtoupper(trim($code));

        return static::$countries[$code] ?? $code;
    }

    /**
     * Get 2-letter ISO country code from full English country name or code.
     */
    public static function getCode(?string $nameOrCode): ?string
    {
        if (! $nameOrCode) {
            return null;
        }

        $trimmed = trim($nameOrCode);
        if (strlen($trimmed) === 2) {
            return strtoupper($trimmed);
        }

        foreach (static::$countries as $code => $name) {
            if (strcasecmp($name, $trimmed) === 0) {
                return $code;
            }
        }

        return null;
    }
}
