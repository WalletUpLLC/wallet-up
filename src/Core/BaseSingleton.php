<?php
namespace WalletUpPro\Core;

use Exception;
use ReflectionClass;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
abstract class BaseSingleton
{
    /** @var array Store singleton instances */
    private static $instances = [];

    /** @var bool Flag to track initialization status */
    protected $initialized = false;

    /** @var array Track initialization errors */
    protected $initErrors = [];

    /**
     * Protected constructor to prevent direct instantiation
     * 
     * @throws Exception if class is already instantiated
     */
    protected function __construct()
    {
        if (isset(self::$instances[static::class])) {
            throw new Exception(esc_html(sprintf(
                'Cannot instantiate singleton class %s. Use getInstance() instead.',
                static::class
            )));
        }
    }

    /**
     * Prevent cloning of singleton instance
     * 
     * @throws Exception if attempted to clone
     */
    protected function __clone()
    {
        throw new Exception(esc_html(sprintf(
            'Cannot clone singleton instance of %s',
            static::class
        )));
    }

    /**
     * Prevent unserialization of singleton instance
     * 
     * @throws Exception if attempted to unserialize
     */
    public function __wakeup()
    {
        throw new Exception(esc_html(sprintf(
            'Cannot unserialize singleton instance of %s',
            static::class
        )));
    }

    /**
     * Get singleton instance
     * 
     * @return static The singleton instance
     * @throws Exception if initialization fails
     */
    public static function getInstance(): self
    {
        $cls = static::class;

        if (!isset(self::$instances[$cls])) {
            $reflection = new ReflectionClass($cls);

            if ($reflection->isAbstract()) {
                throw new Exception(esc_html(sprintf(
                    'Cannot instantiate abstract singleton class %s',
                    $cls
                )));
            }

            self::$instances[$cls] = new static();

            if (method_exists(self::$instances[$cls], 'initialize')) {
                try {
                    self::$instances[$cls]->initialize();
                    self::$instances[$cls]->initialized = true;
                } catch (Exception $e) {
                    unset(self::$instances[$cls]);
                    throw $e;
                }
            }
        }

        return self::$instances[$cls];
    }

    public static function reset(): void
    {
        $cls = static::class;
        if (isset(self::$instances[$cls])) {
            if (method_exists(self::$instances[$cls], 'cleanup')) {
                self::$instances[$cls]->cleanup();
            }
            unset(self::$instances[$cls]);
        }
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getInitializationErrors(): array
    {
        return $this->initErrors;
    }

    protected function addInitializationError(string $error): void
    {
        $this->initErrors[] = $error;
    }

    protected function clearInitializationErrors(): void
    {
        $this->initErrors = [];
    }
}