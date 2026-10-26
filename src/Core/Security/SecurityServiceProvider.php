<?php
namespace WalletUpPro\Core\Security;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class SecurityServiceProvider
{
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function register(): void
    {
        $this->container->set('security', SecurityManager::getInstance());

        add_filter('wallet_up_process_request', [$this, 'processSecurityMiddleware'], 10, 2);
    }

    public function processSecurityMiddleware($response, $request)
    {
        $security = $this->container->get('security');

        try {
            if (!$security->checkRateLimit('api_calls', $security->getClientIdentifier())) {
                throw new \Exception(esc_html('Rate limit exceeded'));
            }

            $validatedData = $security->validateInput($request, [
                'action' => ['required' => true, 'type' => 'string'],
                'data' => ['required' => true]
            ]);

            $cleanData = $security->cleanSensitiveData(wp_json_encode($validatedData));

            if (!$security->checkAccess($validatedData['action'])) {
                throw new \Exception(esc_html('Access denied'));
            }

            return $response;

        } catch (\Exception $e) {
            return new \WP_Error('security_error', $e->getMessage());
        }
    }
}