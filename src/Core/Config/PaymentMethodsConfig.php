<?php
namespace WalletUpPro\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

class PaymentMethodsConfig
{
    const PAYMENT_METHODS = [
        'cashapp' => [
            'name' => 'Cash App',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\CashApp',
            'icon_color' => 'green-600',
            'brand_color' => '#00D632',
            'validation_pattern' => '/^\$?[a-zA-Z0-9]{1,20}$/'
        ],
        'paypal' => [
            'name' => 'PayPal.me',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\PayPal',
            'icon_color' => 'blue-600',
            'brand_color' => '#003087',
            'validation_pattern' => '/^[a-zA-Z0-9._-]{1,}$/'
        ],
        'venmo' => [
            'name' => 'Venmo',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\Venmo',
            'icon_color' => 'blue-600',
            'brand_color' => '#3D95CE',
            'validation_pattern' => '/^@?[a-zA-Z0-9_-]{1,20}$/'
        ],
        'zelle' => [
            'name' => 'Zelle',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\Zelle',
            'icon_color' => 'purple-600',
            'brand_color' => '#6C1CD3',
            'validation_pattern' => '/^([^\s@]+@[^\s@]+\.[^\s@]+|\+?[\d\s-]{10,})$/',
            'has_deep_linking' => true,
            'has_bank_selector' => true
        ],
        'interac' => [
            'payee_label' => 'Email or mobile number',
            'settlement_currency' => 'CAD',
            'name' => 'Interac e-Transfer',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\Interac',
            'icon_color' => 'yellow-600',
            'brand_color' => '#FFB400',
            'validation_pattern' => '/^([^\s@]+@[^\s@]+\.[^\s@]+|\+?[\d\s-]{10,})$/'
        ],
        'payid' => [
            'payee_label' => 'PayID',
            'settlement_currency' => 'AUD',
            'name' => 'PayID',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\PayID',
            'icon_color' => 'teal-600',
            'brand_color' => '#0F7BC4',
            'validation_pattern' => '/^([^\s@]+@[^\s@]+\.[^\s@]+|\+?[\d\s-]{8,}|\d{11})$/'
        ],
        'bpay' => [
            'payee_label' => 'Biller Code',
            'settlement_currency' => 'AUD',
            'name' => 'BPAY',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\BPAY',
            'icon_color' => 'red-600',
            'brand_color' => '#00A9CE',
            'validation_pattern' => '/^\d{4,10}$/'
        ],
        'payto' => [
            'payee_label' => 'PayID or BSB',
            'settlement_currency' => 'AUD',
            'name' => 'PayTo',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\PayTo',
            'icon_color' => 'sky-600',
            'brand_color' => '#003057',
            'validation_pattern' => '/^([^\s@]+@[^\s@]+\.[^\s@]+|\+?[\d\s-]{8,}|\d{11}|\d{6}\s?\d{5,10})$/'
        ],
        'fbpay' => [
            'name' => 'Meta Pay',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\FacebookPay',
            'icon_color' => 'blue-600',
            'brand_color' => '#0866FF',
            'validation_pattern' => '/^[a-zA-Z0-9.]{4,}$/'
        ],
        'waluppay' => [
            'payee_label' => 'WalletUp Pay handle',
            'name' => 'WalletUp Pay',
            'type' => 'traditional',
            'class' => 'WalletUpPro\Payments\WalupPay',
            'icon_color' => 'purple-600',
            'brand_color' => '#674FBF',
            'validation_pattern' => '/^@?([a-zA-Z0-9_][a-zA-Z0-9_-]*[a-zA-Z0-9_]){3,30}$/'
        ],

        'bitcoin' => [
            'name' => 'Bitcoin',
            'type' => 'crypto',
            'class' => 'WalletUpPro\Payments\Bitcoin',
            'icon_color' => 'orange-500',
            'brand_color' => '#F7931A',
            'uri_scheme' => 'bitcoin:',
            'validation_pattern' => '/^([13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1([02-9ac-hj-np-z]){7,87}|sp1([02-9ac-hj-np-z]){8,87})$/i',
            'address_formats' => [
                'legacy' => 'Legacy (1...)',
                'script' => 'Script Hash (3...)',
                'segwit' => 'Native SegWit (bc1q...)',
                'taproot' => 'Taproot (bc1p...)',
                'silent' => 'Silent Payments (sp1q...)'
            ]
        ],
        'ethereum' => [
            'name' => 'Ethereum',
            'type' => 'crypto',
            'class' => 'WalletUpPro\Payments\Ethereum',
            'icon_color' => 'indigo-600',
            'brand_color' => '#627EEA',
            'uri_scheme' => 'ethereum:',
            'validation_pattern' => '/^0x[a-fA-F0-9]{40}$/'
        ],
        'usdt' => [
            'name' => 'USDT (Tether)',
            'type' => 'crypto',
            'class' => 'WalletUpPro\Payments\Usdt',
            'icon_color' => 'green-500',
            'brand_color' => '#26A17B',
            'uri_scheme' => 'ethereum:',
            'validation_pattern' => '/^(0x[a-fA-F0-9]{40}|T[1-9A-HJ-NP-Za-km-z]{33})$/',
            'supports_networks' => ['ethereum', 'tron']
        ],
        'usdc' => [
            'name' => 'USDC',
            'type' => 'crypto',
            'class' => 'WalletUpPro\Payments\Usdc',
            'icon_color' => 'blue-500',
            'brand_color' => '#2775CA',
            'uri_scheme' => 'ethereum:',
            'validation_pattern' => '/^0x[a-fA-F0-9]{40}$/'
        ],
        'bnb' => [
            'name' => 'BNB',
            'type' => 'crypto',
            'class' => 'WalletUpPro\Payments\Bnb',
            'icon_color' => 'yellow-500',
            'brand_color' => '#F3BA2F',
            'uri_scheme' => 'bnb:',
            'validation_pattern' => '/^(0x[a-fA-F0-9]{40}|bnb[a-z0-9]{39})$/',
            'address_formats' => [
                'bep20' => 'BEP-20 (BSC) (0x...)',
                'bep2' => 'BEP-2 (Binance Chain) (bnb...)'
            ],
            'warning' => 'BEP-2 and BEP-20 addresses are NOT interoperable'
        ],
        'dogecoin' => [
            'name' => 'Dogecoin',
            'type' => 'crypto',
            'class' => 'WalletUpPro\Payments\Dogecoin',
            'icon_color' => 'yellow-600',
            'brand_color' => '#C2A633',
            'uri_scheme' => 'dogecoin:',
            'validation_pattern' => '/^[DA9][1-9A-HJ-NP-Za-km-z]{32,46}$/',
            'address_formats' => [
                'standard' => 'Standard (D...)',
                'multisig' => 'Multisig (A... or 9...)'
            ]
        ],

        'mpesa_ke' => [
            'name' => 'M-Pesa',
            'full_name' => 'M-Pesa (Kenya)',
            'type' => 'mobile_money',
            'class' => 'WalletUpPro\Payments\MPesa',
            'icon_color' => 'green-600',
            'brand_color' => '#4CAF50',
            'country' => 'KE',
            'country_code' => '+254',
            'currency' => 'KES',
            'provider' => 'safaricom',
            'requires_phone' => true,
            'validation_pattern' => '/^(\+?254|0)?[17]\d{8}$/'
        ],
        'orange_money_ci' => [
            'name' => 'Orange Money',
            'full_name' => 'Orange Money (Côte d\'Ivoire)',
            'type' => 'mobile_money',
            'class' => 'WalletUpPro\Payments\OrangeMoney',
            'icon_color' => 'orange-500',
            'brand_color' => '#FF7900',
            'country' => 'CI',
            'country_code' => '+225',
            'currency' => 'XOF',
            'provider' => 'orange',
            'requires_phone' => true,
            'validation_pattern' => '/^(\+?225)?[0-9]{10}$/'
        ],
        'mtn_momo_ci' => [
            'name' => 'MTN MoMo',
            'full_name' => 'MTN Mobile Money (Côte d\'Ivoire)',
            'type' => 'mobile_money',
            'class' => 'WalletUpPro\Payments\MtnMomoCi',
            'icon_color' => 'yellow-500',
            'brand_color' => '#FFCC00',
            'country' => 'CI',
            'country_code' => '+225',
            'currency' => 'XOF',
            'provider' => 'mtn',
            'requires_phone' => true,
            'validation_pattern' => '/^(\+?225)?[0-9]{10}$/'
        ],
        'moov_money_ci' => [
            'name' => 'Moov Money',
            'full_name' => 'Moov Money (Côte d\'Ivoire)',
            'type' => 'mobile_money',
            'class' => 'WalletUpPro\Payments\MoovMoney',
            'icon_color' => 'blue-600',
            'brand_color' => '#0072CE',
            'country' => 'CI',
            'country_code' => '+225',
            'currency' => 'XOF',
            'provider' => 'moov',
            'requires_phone' => true,
            'validation_pattern' => '/^(\+?225)?[0-9]{10}$/'
        ],
        'mtn_momo_zm' => [
            'name' => 'MTN MoMo',
            'full_name' => 'MTN Mobile Money (Zambia)',
            'type' => 'mobile_money',
            'class' => 'WalletUpPro\Payments\MtnMomoZm',
            'icon_color' => 'yellow-500',
            'brand_color' => '#FFCC00',
            'country' => 'ZM',
            'country_code' => '+260',
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'requires_phone' => true,
            'validation_pattern' => '/^(\+?260|0)?[0-9]{9}$/'
        ],
        'airtel_money_zm' => [
            'name' => 'Airtel Money',
            'full_name' => 'Airtel Money (Zambia)',
            'type' => 'mobile_money',
            'class' => 'WalletUpPro\Payments\AirtelMoney',
            'icon_color' => 'red-600',
            'brand_color' => '#E4002B',
            'country' => 'ZM',
            'country_code' => '+260',
            'currency' => 'ZMW',
            'provider' => 'airtel',
            'requires_phone' => true,
            'validation_pattern' => '/^(\+?260|0)?[0-9]{9}$/'
        ],
        'zamtel_kwacha' => [
            'name' => 'Zamtel Kwacha',
            'full_name' => 'Zamtel Kwacha (Zambia)',
            'type' => 'mobile_money',
            'class' => 'WalletUpPro\Payments\ZamtelKwacha',
            'icon_color' => 'green-500',
            'brand_color' => '#00A651',
            'country' => 'ZM',
            'country_code' => '+260',
            'currency' => 'ZMW',
            'provider' => 'zamtel',
            'requires_phone' => true,
            'validation_pattern' => '/^(\+?260|0)?[0-9]{9}$/'
        ],
        'wave_ci' => [
            'name' => 'Wave',
            'full_name' => 'Wave (Côte d\'Ivoire)',
            'type' => 'mobile_money',
            'class' => 'WalletUpPro\Payments\WaveCi',
            'icon_color' => 'blue-500',
            'brand_color' => '#1DC8FF',
            'country' => 'CI',
            'country_code' => '+225',
            'currency' => 'XOF',
            'provider' => 'wave',
            'requires_phone' => true,
            'validation_pattern' => '/^(\+?225)?[0-9]{10}$/'
        ]
    ];

    const ADDITIONAL_FIELDS = [
        'holder_owner' => 'Full Name',
        'holder_email' => 'Email Address',
        'holder_no' => 'Phone Number'
    ];

    public static function normalizeHolder(string $methodId, string $value): string
    {
        $value = trim($value);

        switch ($methodId) {
            case 'paypal':
                $value = preg_replace('#^https?://#i', '', $value);
                $value = preg_replace('#^www\.#i', '', $value);
                $value = preg_replace('#^paypal\.com/paypalme/#i', '', $value);
                $value = preg_replace('#^paypal\.me/#i', '', $value);
                $value = ltrim($value, '@');
                break;
            case 'cashapp':
                $value = preg_replace('#^https?://#i', '', $value);
                $value = preg_replace('#^cash\.app/#i', '', $value);
                break;
            case 'venmo':
                $value = preg_replace('#^https?://#i', '', $value);
                $value = preg_replace('#^www\.#i', '', $value);
                $value = preg_replace('#^venmo\.com/(u/)?#i', '', $value);
                break;
            default:
                return $value;
        }

        return preg_replace('#[/?\#].*$#', '', $value);
    }

    public const FREE_METHOD_IDS = ['cashapp', 'paypal', 'venmo'];

    public const CUSTOM_QR_METHOD_IDS = ['zelle'];

    public static function allowsCustomQr(string $methodId): bool
    {
        return in_array($methodId, self::CUSTOM_QR_METHOD_IDS, true);
    }

    public static function getAllMethodIds(): array
    {
        return array_keys(self::PAYMENT_METHODS);
    }

    public static function getAvailableMethodIds(): array
    {
        if (class_exists('WalletUpPro\\Payments\\Bitcoin') || class_exists('WalletUpPro\\Payments\\Zelle')) {
            return self::getAllMethodIds();
        }

        return self::FREE_METHOD_IDS;
    }

    public static function getPaymentHolderFields(): array
    {
        return array_map(fn($id) => $id . '_holder', self::getAllMethodIds());
    }

    public static function getAllHolderFields(): array
    {
        return array_merge(
            array_keys(self::ADDITIONAL_FIELDS),
            self::getPaymentHolderFields()
        );
    }

    public static function getMethodConfig(string $methodId): ?array
    {
        return self::PAYMENT_METHODS[$methodId] ?? null;
    }

    public static function getMethodName(string $methodId): string
    {
        return self::PAYMENT_METHODS[$methodId]['name'] ?? ucfirst($methodId);
    }

    public static function getMethodFullName(string $methodId): string
    {
        return self::PAYMENT_METHODS[$methodId]['full_name']
            ?? self::PAYMENT_METHODS[$methodId]['name']
            ?? ucfirst($methodId);
    }

    public static function getMethodsByType(string $type): array
    {
        return array_filter(
            self::PAYMENT_METHODS,
            fn($config) => $config['type'] === $type,
            ARRAY_FILTER_USE_BOTH
        );
    }

    public static function getBrandColor(string $methodId): string
    {
        return self::PAYMENT_METHODS[$methodId]['brand_color'] ?? '#674FBF';
    }

    public static function getMethodClass(string $methodId): ?string
    {
        return self::PAYMENT_METHODS[$methodId]['class'] ?? null;
    }

    public static function getValidationPattern(string $methodId): ?string
    {
        return self::PAYMENT_METHODS[$methodId]['validation_pattern'] ?? null;
    }

    public static function methodHasFeature(string $methodId, string $feature): bool
    {
        return !empty(self::PAYMENT_METHODS[$methodId][$feature]);
    }

    public static function getFieldLabel(string $field): string
    {
        if (isset(self::ADDITIONAL_FIELDS[$field])) {
            return self::ADDITIONAL_FIELDS[$field];
        }

        $methodId = str_replace('_holder', '', $field);
        if (isset(self::PAYMENT_METHODS[$methodId])) {
            return self::PAYMENT_METHODS[$methodId]['name'];
        }

        return ucfirst(str_replace('_', ' ', $field));
    }

    public static function qrPayload(string $methodId, string $url, string $holder): string
    {
        $url = trim($url);
        return $url !== '' ? $url : trim($holder);
    }

    public static function getUriScheme(string $methodId): string
    {
        return self::PAYMENT_METHODS[$methodId]['uri_scheme'] ?? '';
    }

    public static function isCryptocurrency(string $methodId): bool
    {
        return (self::PAYMENT_METHODS[$methodId]['type'] ?? '') === 'crypto';
    }

    public static function isMobileMoney(string $methodId): bool
    {
        return (self::PAYMENT_METHODS[$methodId]['type'] ?? '') === 'mobile_money';
    }

    public static function requiresPhoneNumber(string $methodId): bool
    {
        return !empty(self::PAYMENT_METHODS[$methodId]['requires_phone']);
    }

    public static function getCountryCode(string $methodId): ?string
    {
        return self::PAYMENT_METHODS[$methodId]['country_code'] ?? null;
    }

    public static function getCurrency(string $methodId): ?string
    {
        return self::PAYMENT_METHODS[$methodId]['currency'] ?? null;
    }

    /**
     * Mobile money method IDs. Every caller uses these as ids — to index enabled-method settings
     * and for in_array checks — but array_filter preserves keys, so returning the map made one
     * caller fatal on an array offset and left the other four silently matching nothing.
     *
     * @return string[]
     */
    public static function getMobileMoneyMethods(): array
    {
        return array_keys(self::getMethodsByType('mobile_money'));
    }

    public static function getMobileMoneyByCountry(string $countryCode): array
    {
        return array_filter(
            self::PAYMENT_METHODS,
            fn($config) => ($config['type'] ?? '') === 'mobile_money'
                && ($config['country'] ?? '') === strtoupper($countryCode)
        );
    }
}
