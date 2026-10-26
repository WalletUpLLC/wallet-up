<?php
namespace WalletUpPro\Helpers;

use ReflectionClass;
use Exception;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class ClassManager
{
    private static $instances = [];
    private static $disabledClasses = [];
    private static $classRegistry = [];
    private static $dependencies = [];
    private static $logger;
    private static $autoRegistrationEnabled = true;
    private static $allowedClasses = [];

    /**
     * Sets the logger for the ClassManager to use for logging.
     *
     * @param callable $logger A callable that accepts a single string argument for logging messages.
     */
    public static function setLogger(callable $logger): void
    {
        self::$logger = $logger;
    }

    private static function log($message): void
    {
        if (self::$logger) {
            call_user_func(self::$logger, $message);
        } else {
        }
    }

    /**
     * Register a class in the manager with extensive options.
     *
     * @param string $className Fully qualified class name.
     * @param callable|null $initializer A callback function to initialize the class.
     * @param array $dependencies List of dependent class names.
     * @param array $constructorArgs Constructor arguments for dependency injection.
     * @param bool $lazyLoad Whether to lazy load the class.
     * @param callable|null $factoryMethod A factory method to create instances.
     * @param array $requiredInterfaces List of interfaces the class must implement.
     * @throws \InvalidArgumentException If class or initializer parameters are invalid.
     */
    public static function register(string $className, ?callable $initializer = null, array $dependencies = [], array $constructorArgs = [], bool $lazyLoad = false, ?callable $factoryMethod = null, array $requiredInterfaces = []): void
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException(esc_html("Class $className does not exist."));
        }

        if (!self::isAllowedClass($className)) {
            throw new \SecurityException(esc_html("Class $className is not allowed to be registered."));
        }

        if ($initializer !== null && !is_callable($initializer)) {
            throw new \InvalidArgumentException(esc_html("Initializer for $className must be callable."));
        }

        if (isset(self::$classRegistry[$className])) {
            self::log("Class $className is already registered. Registration skipped.");
            return;
        }

        $reflectionClass = new ReflectionClass($className);
        foreach ($requiredInterfaces as $interface) {
            if (!$reflectionClass->implementsInterface($interface)) {
                throw new \InvalidArgumentException(esc_html("Class $className must implement $interface."));
            }
        }

        self::$classRegistry[$className] = $initializer ?? function () use ($className, $constructorArgs) {
            return new $className(...$constructorArgs);
        };

        self::$dependencies[$className] = [
            'dependencies' => $dependencies,
            'constructorArgs' => $constructorArgs,
            'lazyLoad' => $lazyLoad,
            'factoryMethod' => $factoryMethod
        ];

        self::log("Class $className registered successfully with dependencies: " . implode(', ', $dependencies));
    }

    /**
     * Validated auto-registration for approved classes if not registered.
     *
     * @param string $className Fully qualified class name.
     */
    private static function autoRegister(string $className): void
    {
        if (!self::$autoRegistrationEnabled || !self::isAllowedClass($className)) {
            return;
        }

        if (!isset(self::$classRegistry[$className])) {
            try {
                self::register($className);
                self::log("Auto-registered class $className.");
            } catch (\Exception $e) {
                self::log("Failed to auto-register class $className: " . $e->getMessage());
            }
        }
    }

    public static function disable(string $className): void
    {
        self::autoRegister($className);

        if (self::isDisabled($className)) {
            self::log("Class $className is already disabled.");
            return;
        }

        self::$disabledClasses[$className] = true;
        self::log("Class $className has been disabled.");
    }

    public static function enable(string $className): void
    {
        self::autoRegister($className);

        if (!self::isDisabled($className)) {
            self::log("Class $className is already enabled.");
            return;
        }

        unset(self::$disabledClasses[$className]);
        self::log("Class $className has been enabled.");
    }

    public static function isDisabled(string $className): bool
    {
        return self::$disabledClasses[$className] ?? false;
    }

    /**
     * Initialize a class if it's registered, dependencies are active, and it is not disabled.
     *
     * @param string $className Fully qualified class name.
     * @return object|null The class instance or null if disabled/unregistered/failed dependencies.
     */
    public static function initialize(string $className)
    {
        self::autoRegister($className);

        if (!isset(self::$classRegistry[$className])) {
            self::log("Class $className is not registered in ClassManager.");
            return null;
        }

        if (self::isDisabled($className)) {
            self::log("Class $className is disabled and will not be initialized.");
            return null;
        }

        $dependencyInstances = [];
        foreach (self::$dependencies[$className]['dependencies'] ?? [] as $dependency) {
            if (self::isDisabled($dependency)) {
                self::log("Class $className cannot be initialized because its dependency $dependency is disabled.");
                return null;
            }

            $dependencyInstance = self::initialize($dependency);
            if (!$dependencyInstance) {
                self::log("Class $className cannot be initialized because its dependency $dependency failed to initialize.");
                return null;
            }
            $dependencyInstances[$dependency] = $dependencyInstance;
        }

        if (self::$dependencies[$className]['lazyLoad']) {
            if (!isset(self::$instances[$className])) {
                self::$instances[$className] = function () use ($className, $dependencyInstances) {
                    return self::initializeEagerly($className, $dependencyInstances);
                };
            }
            return self::$instances[$className];
        }

        return self::initializeEagerly($className, $dependencyInstances);
    }

    /**
     * Eager initialization of a class.
     *
     * @param string $className Fully qualified class name.
     * @param array $dependencyInstances Instances of dependencies.
     * @return object|null
     */
    private static function initializeEagerly(string $className, array $dependencyInstances)
    {
        if (!isset(self::$instances[$className])) {
            try {
                $args = self::$dependencies[$className]['constructorArgs'] ?? [];
                foreach ($args as &$arg) {
                    if (is_string($arg) && isset($dependencyInstances[$arg])) {
                        $arg = $dependencyInstances[$arg];
                    }
                }

                $factoryMethod = self::$dependencies[$className]['factoryMethod'] ?? null;
                if ($factoryMethod) {
                    self::$instances[$className] = call_user_func($factoryMethod, ...$dependencyInstances);
                } else {
                    self::$instances[$className] = call_user_func_array(self::$classRegistry[$className], $args);
                }

                self::log("Class $className initialized successfully.");
            } catch (\Throwable $e) {
                self::log("Failed to initialize class $className: " . $e->getMessage());
                self::$instances[$className] = null;
            }
        }
        return self::$instances[$className];
    }

    public static function unregister(string $className): void
    {
        if (!isset(self::$classRegistry[$className])) {
            self::log("Cannot unregister $className: Class not registered.");
            return;
        }

        unset(self::$classRegistry[$className]);
        unset(self::$instances[$className]);
        unset(self::$disabledClasses[$className]);
        unset(self::$dependencies[$className]);

        self::log("Class $className has been unregistered.");
    }

    public static function getStatus(): array
    {
        $statuses = [];
        foreach (self::$classRegistry as $className => $initializer) {
            $statuses[$className] = self::isDisabled($className) ? 'disabled' : 'enabled';
        }
        return $statuses;
    }

    public static function logStatus(): void
    {
        $statuses = self::getStatus();
        foreach ($statuses as $className => $status) {
            self::log("Class $className is $status.");
        }
    }

    public static function setAutoRegistration(bool $enable): void
    {
        self::$autoRegistrationEnabled = $enable;
        self::log("Auto-registration " . ($enable ? "enabled" : "disabled") . ".");
    }

    public static function addToAllowedClasses(array $classes): void
    {
        self::$allowedClasses = array_merge(self::$allowedClasses, $classes);
    }

    private static function isAllowedClass(string $className): bool
    {
        return empty(self::$allowedClasses) || in_array($className, self::$allowedClasses);
    }

    public static function getDependencyGraph(): array
    {
        $graph = [];
        foreach (self::$dependencies as $className => $depInfo) {
            $graph[$className] = $depInfo['dependencies'];
        }
        return $graph;
    }
}