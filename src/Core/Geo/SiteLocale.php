<?php
namespace WalletUpPro\Core\Geo;

use WalletUpPro\Core\Config\PaymentMethodsConfig;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Best-effort guess of the country a site operates in, so the plugin can say up
 * front which payment apps will work there (6.5.0). The owner can override it;
 * the guess is never used to block anything, only to inform and pre-select.
 */
final class SiteLocale
{
    public const OPTION_OVERRIDE = 'wallet_up_site_country';
    public const OPTION_HINT     = 'wallet_up_geo_hint';

    private const LOCALE_COUNTRY = [
        'en_US' => 'US', 'es_US' => 'US', 'en_GB' => 'GB', 'en_CA' => 'CA', 'fr_CA' => 'CA', 'en_AU' => 'AU', 'en_NZ' => 'NZ',
        'en_IN' => 'IN', 'hi_IN' => 'IN', 'bn_BD' => 'BD', 'ur' => 'PK', 'de_DE' => 'DE', 'de_AT' => 'AT', 'de_CH' => 'CH',
        'fr_FR' => 'FR', 'fr_BE' => 'BE', 'nl_NL' => 'NL', 'nl_BE' => 'BE', 'es_ES' => 'ES', 'es_MX' => 'MX', 'es_AR' => 'AR',
        'es_CO' => 'CO', 'es_CL' => 'CL', 'pt_BR' => 'BR', 'pt_PT' => 'PT', 'it_IT' => 'IT', 'pl_PL' => 'PL', 'sv_SE' => 'SE',
        'nb_NO' => 'NO', 'da_DK' => 'DK', 'fi' => 'FI', 'tr_TR' => 'TR', 'ru_RU' => 'RU', 'uk' => 'UA', 'ja' => 'JP',
        'zh_CN' => 'CN', 'zh_TW' => 'TW', 'ko_KR' => 'KR', 'id_ID' => 'ID', 'vi' => 'VN', 'th' => 'TH', 'en_ZA' => 'ZA',
        'en_NG' => 'NG', 'sw' => 'KE', 'fa_IR' => 'IR', 'ar' => 'SA', 'he_IL' => 'IL', 'lt_LT' => 'LT', 'hu_HU' => 'HU',
        'cs_CZ' => 'CZ', 'ro_RO' => 'RO', 'el' => 'GR', 'en_PH' => 'PH', 'ms_MY' => 'MY', 'en_SG' => 'SG',
    ];

    private const TIMEZONE_COUNTRY = [
        'America/New_York' => 'US', 'America/Chicago' => 'US', 'America/Denver' => 'US', 'America/Los_Angeles' => 'US',
        'America/Phoenix' => 'US', 'America/Anchorage' => 'US', 'Pacific/Honolulu' => 'US', 'America/Detroit' => 'US',
        'America/Toronto' => 'CA', 'America/Vancouver' => 'CA', 'America/Edmonton' => 'CA', 'America/Winnipeg' => 'CA', 'America/Halifax' => 'CA',
        'America/Mexico_City' => 'MX', 'America/Sao_Paulo' => 'BR', 'America/Argentina/Buenos_Aires' => 'AR', 'America/Bogota' => 'CO', 'America/Santiago' => 'CL',
        'Europe/London' => 'GB', 'Europe/Dublin' => 'IE', 'Europe/Berlin' => 'DE', 'Europe/Paris' => 'FR', 'Europe/Madrid' => 'ES',
        'Europe/Rome' => 'IT', 'Europe/Amsterdam' => 'NL', 'Europe/Brussels' => 'BE', 'Europe/Lisbon' => 'PT', 'Europe/Warsaw' => 'PL',
        'Europe/Stockholm' => 'SE', 'Europe/Oslo' => 'NO', 'Europe/Copenhagen' => 'DK', 'Europe/Helsinki' => 'FI', 'Europe/Vilnius' => 'LT',
        'Europe/Zurich' => 'CH', 'Europe/Vienna' => 'AT', 'Europe/Istanbul' => 'TR', 'Europe/Athens' => 'GR', 'Europe/Budapest' => 'HU',
        'Asia/Kolkata' => 'IN', 'Asia/Calcutta' => 'IN', 'Asia/Karachi' => 'PK', 'Asia/Dhaka' => 'BD', 'Asia/Jakarta' => 'ID', 'Asia/Manila' => 'PH',
        'Asia/Singapore' => 'SG', 'Asia/Kuala_Lumpur' => 'MY', 'Asia/Bangkok' => 'TH', 'Asia/Ho_Chi_Minh' => 'VN', 'Asia/Tokyo' => 'JP',
        'Asia/Seoul' => 'KR', 'Asia/Shanghai' => 'CN', 'Asia/Hong_Kong' => 'HK', 'Asia/Dubai' => 'AE', 'Asia/Riyadh' => 'SA', 'Asia/Tehran' => 'IR',
        'Africa/Lagos' => 'NG', 'Africa/Nairobi' => 'KE', 'Africa/Johannesburg' => 'ZA', 'Africa/Abidjan' => 'CI', 'Africa/Lusaka' => 'ZM', 'Africa/Cairo' => 'EG',
        'Australia/Sydney' => 'AU', 'Australia/Melbourne' => 'AU', 'Australia/Brisbane' => 'AU', 'Australia/Perth' => 'AU', 'Pacific/Auckland' => 'NZ',
    ];

    private const COUNTRY_CURRENCY = [
        'US' => 'USD', 'GB' => 'GBP', 'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD', 'IN' => 'INR', 'PK' => 'PKR', 'BD' => 'BDT',
        'DE' => 'EUR', 'AT' => 'EUR', 'FR' => 'EUR', 'BE' => 'EUR', 'NL' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR', 'PT' => 'EUR',
        'IE' => 'EUR', 'FI' => 'EUR', 'LT' => 'EUR', 'GR' => 'EUR', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK', 'DK' => 'DKK',
        'PL' => 'PLN', 'HU' => 'HUF', 'CZ' => 'CZK', 'RO' => 'RON', 'TR' => 'TRY', 'MX' => 'MXN', 'BR' => 'BRL', 'AR' => 'ARS',
        'CO' => 'COP', 'CL' => 'CLP', 'JP' => 'JPY', 'CN' => 'CNY', 'HK' => 'HKD', 'KR' => 'KRW', 'ID' => 'IDR', 'PH' => 'PHP',
        'SG' => 'SGD', 'MY' => 'MYR', 'TH' => 'THB', 'VN' => 'VND', 'AE' => 'AED', 'SA' => 'SAR', 'NG' => 'NGN', 'KE' => 'KES',
        'ZA' => 'ZAR', 'CI' => 'XOF', 'ZM' => 'ZMW', 'EG' => 'EGP', 'IR' => 'IRR',
    ];

    private const COUNTRY_NAMES = [
        'US' => 'the United States', 'GB' => 'the United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'IN' => 'India',
        'PK' => 'Pakistan', 'BD' => 'Bangladesh', 'DE' => 'Germany', 'AT' => 'Austria', 'FR' => 'France', 'BE' => 'Belgium', 'NL' => 'the Netherlands',
        'ES' => 'Spain', 'IT' => 'Italy', 'PT' => 'Portugal', 'IE' => 'Ireland', 'FI' => 'Finland', 'LT' => 'Lithuania', 'GR' => 'Greece',
        'CH' => 'Switzerland', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'PL' => 'Poland', 'HU' => 'Hungary', 'CZ' => 'Czechia',
        'RO' => 'Romania', 'TR' => 'Türkiye', 'MX' => 'Mexico', 'BR' => 'Brazil', 'AR' => 'Argentina', 'CO' => 'Colombia', 'CL' => 'Chile',
        'JP' => 'Japan', 'CN' => 'China', 'HK' => 'Hong Kong', 'KR' => 'South Korea', 'ID' => 'Indonesia', 'PH' => 'the Philippines',
        'SG' => 'Singapore', 'MY' => 'Malaysia', 'TH' => 'Thailand', 'VN' => 'Vietnam', 'AE' => 'the United Arab Emirates', 'SA' => 'Saudi Arabia',
        'NG' => 'Nigeria', 'KE' => 'Kenya', 'ZA' => 'South Africa', 'CI' => "Côte d'Ivoire", 'ZM' => 'Zambia', 'EG' => 'Egypt', 'IR' => 'Iran',
    ];

    /** @return array{country:string,source:string} */
    public static function detect(): array
    {
        $override = strtoupper(trim((string) get_option(self::OPTION_OVERRIDE, '')));
        if (preg_match('/^[A-Z]{2}$/', $override)) {
            return ['country' => $override, 'source' => 'owner'];
        }
        $wc = (string) get_option('woocommerce_default_country', '');
        if ($wc !== '' && preg_match('/^([A-Z]{2})/', $wc, $m)) {
            return ['country' => $m[1], 'source' => 'woocommerce'];
        }
        $tz = (string) get_option('timezone_string', '');
        if ($tz !== '' && isset(self::TIMEZONE_COUNTRY[$tz])) {
            return ['country' => self::TIMEZONE_COUNTRY[$tz], 'source' => 'timezone'];
        }
        $locale = (string) get_locale();
        if (isset(self::LOCALE_COUNTRY[$locale])) {
            return ['country' => self::LOCALE_COUNTRY[$locale], 'source' => 'locale'];
        }
        $lang = strtok($locale, '_');
        if ($lang !== false && isset(self::LOCALE_COUNTRY[$lang])) {
            return ['country' => self::LOCALE_COUNTRY[$lang], 'source' => 'locale'];
        }
        return ['country' => '', 'source' => 'unknown'];
    }

    /** Countries offered in the "choose your country" control: code => name, US first. */
    public static function pickList(): array
    {
        $codes = ['US', 'CA', 'GB', 'AU', 'NZ', 'IE', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'PT', 'SE', 'NO', 'DK', 'PL', 'CH', 'AT', 'MX', 'BR', 'AR', 'CO', 'IN', 'PK', 'BD', 'PH', 'ID', 'MY', 'SG', 'JP', 'KR', 'AE', 'SA', 'IL', 'TR', 'NG', 'GH', 'KE', 'ZA', 'EG', 'MA'];
        $out = [];
        foreach ($codes as $cc) {
            $out[$cc] = self::countryName($cc);
        }
        return $out;
    }

    public static function countryName(string $code): string
    {
        return self::COUNTRY_NAMES[strtoupper($code)] ?? strtoupper($code);
    }

    public static function currencyFor(string $code): string
    {
        return self::COUNTRY_CURRENCY[strtoupper($code)] ?? 'USD';
    }

    /**
     * The hint handed to the admin app and the setup assistant. Recomputed on every
     * call because it is cheap and the inputs can change under the owner's feet.
     */
    public static function hint(): array
    {
        $d = self::detect();
        $country = $d['country'];
        $free = PaymentMethodsConfig::FREE_METHOD_IDS;
        $works = $country === '' ? $free : PaymentMethodsConfig::freeMethodsFor($country);
        $usOnly = array_values(array_diff($free, $works));
        $hint = [
            'country'      => $country,
            'countryName'  => $country === '' ? '' : self::countryName($country),
            'source'       => $d['source'],
            'isUS'         => $country === 'US' || $country === '',
            'works'        => $works,
            'doesNotWork'  => $usOnly,
            'worldwide'    => ['paypal'],
            'currency'     => $country === '' ? 'USD' : self::currencyFor($country),
        ];
        update_option(self::OPTION_HINT, $hint, false);
        return $hint;
    }

    /** Called from the activation hook: stores the hint and seeds the site currency once. */
    public static function onActivation(): void
    {
        $hint = self::hint();
        $settings = get_option('wallet_up_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        if (empty($settings['currency_set_by_owner'])) {
            $wcCurrency = strtoupper((string) get_option('woocommerce_currency', ''));
            $settings['currency'] = preg_match('/^[A-Z]{3}$/', $wcCurrency) ? $wcCurrency : ($hint['currency'] ?? 'USD');
            update_option('wallet_up_settings', $settings);
        }
    }
}
