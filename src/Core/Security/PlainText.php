<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

final class PlainText
{
    private const CONTROL    = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u';
    private const INVISIBLE  = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';
    private const BLOCK_TAGS = '/<(script|style|iframe|object|embed|noscript|template)\b[^>]*>.*?<\/\1\s*>/isu';
    private const ANY_TAG    = '/<\/?[a-z!?\/][^>]*>/iu';
    private const URL_SCHEMES = ['http', 'https'];
    private const SCAN_FACTOR = 4;

    public static function text($value, int $max): string
    {
        $s = self::base($value, $max);
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = (string) preg_replace('/[ \t]+$/mu', '', $s);
        $s = (string) preg_replace("/\n{3,}/u", "\n\n", $s);
        return self::cut(trim($s), $max);
    }

    public static function line($value, int $max): string
    {
        $s = self::base($value, $max);
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        return self::cut(trim($s), $max);
    }

    public static function url($value, int $max): string
    {
        $s = self::line($value, $max);
        if ($s === '' || mb_strlen($s) >= $max) {
            return '';
        }
        $parts = parse_url($s);
        if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), self::URL_SCHEMES, true)) {
            return '';
        }
        return filter_var($s, FILTER_VALIDATE_URL) === false ? '' : $s;
    }

    private static function base($value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $s = (string) $value;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = mb_substr($s, 0, max(1, $max) * self::SCAN_FACTOR);
        $s = (string) preg_replace(self::BLOCK_TAGS, ' ', $s);
        $s = (string) preg_replace(self::ANY_TAG, '', $s);
        $s = (string) preg_replace(self::INVISIBLE, '', $s);
        return (string) preg_replace(self::CONTROL, '', $s);
    }

    private static function cut(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)) : $s;
    }
}
