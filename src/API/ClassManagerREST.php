<?php
namespace WalletUpPro\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WalletUpPro\Helpers\ClassManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class ClassManagerREST extends WP_REST_Controller
{
    protected $namespace = 'wallet-up-pro/v1';
    protected $rest_base = 'class-manager';

    public function register_routes()
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/status', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_status'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ]
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/dependencies', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_dependencies'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ]
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/enable', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'enable_class'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args' => [
                    'className' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ]
                ]
            ]
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/disable', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'disable_class'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args' => [
                    'className' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ]
                ]
            ]
        ]);
    }

    public function check_admin_permissions(): bool
    {
        return current_user_can('manage_options');
    }

    public function get_status(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $status = ClassManager::getStatus();
            return new WP_REST_Response($status, 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function get_dependencies(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $dependencies = ClassManager::getDependencyGraph();
            return new WP_REST_Response($dependencies, 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function enable_class(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $className = $request->get_param('className');
            ClassManager::enable($className);
            return new WP_REST_Response([
                'message' => "Class {$className} enabled successfully",
                'status' => ClassManager::getStatus()[$className]
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function disable_class(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $className = $request->get_param('className');
            ClassManager::disable($className);
            return new WP_REST_Response([
                'message' => "Class {$className} disabled successfully",
                'status' => ClassManager::getStatus()[$className]
            ], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public static function init()
    {
        $controller = new self();
        add_action('rest_api_init', [$controller, 'register_routes']);

    }
}