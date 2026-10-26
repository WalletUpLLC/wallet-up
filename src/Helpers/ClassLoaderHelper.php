<?php
namespace WalletUpPro\Helpers;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class ClassLoaderHelper
{
    private static $disabledClasses = [];

    public static function disableClass(string $className): void
    {
        self::$disabledClasses[$className] = true;
    }

    public static function isClassDisabled(string $className): bool
    {
        return self::$disabledClasses[$className] ?? false;
    }

    /**
     * Safely initialize a class if it's not disabled.
     *
     * @param string $className Fully qualified class name.
     * @param callable $initializer A callback to initialize the class.
     */
    public static function initializeClass(string $className, callable $initializer): void
    {
        if (self::isClassDisabled($className)) {
            return;
        }

        call_user_func($initializer);
    }

    public static function getClassStatus(string $className): string
    {
        return self::isClassDisabled($className) ? 'disabled' : 'enabled';
    }
}
