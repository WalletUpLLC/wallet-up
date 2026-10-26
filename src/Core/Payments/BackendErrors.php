<?php

declare(strict_types=1);

namespace WalletUpPro\Core\Payments;

if (!defined('ABSPATH')) {
    exit;
}

final class BackendErrors
{
    public const TRANSPORT_CODES = ['http_request_failed'];

    private static $logger = null;

    public static function setLogger(?callable $logger): void
    {
        self::$logger = $logger;
    }

    public static function isTransport(\WP_Error $error): bool
    {
        return in_array($error->get_error_code(), self::TRANSPORT_CODES, true);
    }

    public static function unreachable(): string
    {
        return __('We couldn’t reach the payment service from this site. Please try again in a few minutes. If it keeps happening, contact support.', 'walletup');
    }

    public static function forCustomer(\WP_Error $error, string $context = ''): string
    {
        if (!self::isTransport($error)) {
            return $error->get_error_message();
        }
        $line = 'Payment service unreachable' . ($context !== '' ? ' (' . $context . ')' : '') . ': ' . $error->get_error_message();
        if (self::$logger !== null) {
            call_user_func(self::$logger, $line);
        } elseif (class_exists('\\WalletUpPro\\Core\\Error\\ErrorHandler')) {
            \WalletUpPro\Core\Error\ErrorHandler::getInstance()->log($line, 'Error');
        }
        return self::unreachable();
    }
}
