<?php
namespace WalletUpPro\Core;

use WalletUpPro\Core\Error\ErrorHandler;
use WalletUpPro\Core\Database\DatabaseManager;
use WalletUpPro\Services\QRCodeService;
use WalletUpPro\Services\ChartService;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class ServiceContainer extends BaseSingleton
{
    private array $services = [];
    private ErrorHandler $errorHandler;

    protected function initialize(): void
    {
        try {
            $this->errorHandler = ErrorHandler::getInstance();
            $this->initializeCore();
        } catch (\Exception $e) {
            $this->handleInitializationError($e);
        }
    }

    private function initializeCore(): void
    {
        try {
            global $wpdb;
            $tableName = $wpdb->prefix . 'wallet_up_analytics';

            if (!class_exists(DatabaseManager::class)) {
                throw new \Exception(esc_html('DatabaseManager class not found. Check autoloader configuration.'));
            }

            $database = new DatabaseManager($tableName);
            $this->services['database'] = $database;

            $this->services['chart'] = ChartService::getInstance();

            register_shutdown_function([$this, 'cleanup']);

        } catch (\Exception $e) {
            $this->handleServiceInitializationError($e);
        }
    }

    private function handleInitializationError(\Exception $e): void
    {
        $this->errorHandler->log('Service container initialization failed: ' . $e->getMessage());
        throw new \Exception(esc_html('Service container initialization failed: ' . $e->getMessage()));
    }

    private function handleServiceInitializationError(\Exception $e): void
    {
        $this->errorHandler->log('Service initialization failed: ' . $e->getMessage());
        throw new \Exception(esc_html('Service initialization failed: ' . $e->getMessage()));
    }

    /**
     * Get a service instance
     * 
     * @param string $name Service name
     * @return mixed Service instance
     * @throws \Exception if service not found
     */
    public function get(string $name)
    {
        if (!$this->initialized) {
            throw new \Exception(esc_html('Service container not properly initialized'));
        }

        if (!isset($this->services[$name])) {
            throw new \Exception(esc_html("Service not found: {$name}"));
        }
        return $this->services[$name];
    }

    public function set(string $name, $service): void
    {
        $this->services[$name] = $service;
    }

    public function has(string $name): bool
    {
        return isset($this->services[$name]);
    }

    public function getDatabase(): DatabaseManager
    {
        return $this->get('database');
    }

    public function getQRService(): QRCodeService
    {
        if (!isset($this->services['qr']) || !$this->services['qr'] instanceof QRCodeService) {
            $this->services['qr'] = QRCodeService::getInstance();
        }
        return $this->services['qr'];
    }

    public function getChartService(): ChartService
    {
        return $this->get('chart');
    }

    public function getErrorHandler(): ErrorHandler
    {
        return $this->errorHandler;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function cleanup(): void
    {
        try {
            foreach ($this->services as $service) {
                if (method_exists($service, 'cleanup')) {
                    $service->cleanup();
                }
            }
        } catch (\Exception $e) {
            $this->errorHandler->log('Cleanup failed: ' . $e->getMessage());
        }
    }

    protected function __clone()
    {
    }

    public function __wakeup()
    {
        throw new \Exception(esc_html("Cannot unserialize singleton"));
    }
}