<?php
namespace WalletUpPro\Core\Security;

use WalletUpPro\Core\BaseSingleton;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class InputValidator extends BaseSingleton
{
    /** @var array Basic validation rules */
    private const VALIDATION_RULES = [
        'email' => FILTER_VALIDATE_EMAIL,
        'url' => FILTER_VALIDATE_URL,
        'ip' => FILTER_VALIDATE_IP,
        'int' => FILTER_VALIDATE_INT,
        'float' => FILTER_VALIDATE_FLOAT
    ];
    
    /** @var array PayPal-specific validation rules */
    private const PAYPAL_RULES = [
        'paypal_client_id' => [
            'min_length' => 20,
            'max_length' => 200,
            'pattern' => '/^[A-Za-z0-9._-]+$/',
            'message' => 'PayPal Client ID must be 20-200 characters and contain only letters, numbers, periods, hyphens, and underscores'
        ],
        'paypal_client_secret' => [
            'min_length' => 20,
            'max_length' => 200,
            'pattern' => '/^[A-Za-z0-9._-]+$/',
            'message' => 'PayPal Client Secret must be 20-200 characters and contain only letters, numbers, periods, hyphens, and underscores'
        ],
        'paypal_webhook_id' => [
            'min_length' => 17,
            'max_length' => 17,
            'pattern' => '/^[A-Z0-9]{17}$/',
            'message' => 'PayPal Webhook ID must be exactly 17 uppercase alphanumeric characters'
        ],
        'bitcoin_address' => [
            'pattern' => '/^([13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1([02-9ac-hj-np-z]){7,87}|sp1([02-9ac-hj-np-z]){8,87})$/i',
            'message' => 'Invalid Bitcoin address format (supports Legacy, SegWit, Taproot, and Silent Payments)'
        ],
        'ethereum_address' => [
            'pattern' => '/^0x[a-fA-F0-9]{40}$/',
            'message' => 'Invalid Ethereum address format'
        ],
        'usdt_address' => [
            'pattern' => '/^(0x[a-fA-F0-9]{40}|T[1-9A-HJ-NP-Za-km-z]{33})$/',
            'message' => 'Invalid USDT address format (supports both ERC-20 and TRC-20)'
        ],
        'usdc_address' => [
            'pattern' => '/^0x[a-fA-F0-9]{40}$/',
            'message' => 'Invalid USDC address format'
        ],
        'bnb_address' => [
            'pattern' => '/^(0x[a-fA-F0-9]{40}|bnb[a-z0-9]{39})$/',
            'message' => 'Invalid BNB address format (supports both BEP-20 and BEP-2)'
        ],
        'dogecoin_address' => [
            'pattern' => '/^[DA9][1-9A-HJ-NP-Za-km-z]{32,46}$/',
            'message' => 'Invalid Dogecoin address format (supports Standard and Multisig)'
        ]
    ];
    
    /** @var array Common domain suggestions for typo correction */
    private const COMMON_DOMAINS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 
        'icloud.com', 'aol.com', 'live.com', 'msn.com',
        'paypal.com', 'business.com', 'company.com'
    ];

    public function validate(array $data, array $rules): array
    {
        $validated = [];

        foreach ($rules as $field => $rule) {
            if (!isset($data[$field])) {
                if (!empty($rule['required'])) {
                    throw new \Exception(esc_html("Field {$field} is required"));
                }
                continue;
            }

            $value = $data[$field];

            if (isset($rule['type']) && isset(self::VALIDATION_RULES[$rule['type']])) {
                $filtered = filter_var($value, self::VALIDATION_RULES[$rule['type']]);
                if ($filtered === false) {
                    throw new \Exception(esc_html("Invalid {$rule['type']} format for field {$field}"));
                }
                $value = $filtered;
            }

            if (!empty($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                throw new \Exception(esc_html("Field {$field} does not match required pattern"));
            }

            if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
                throw new \Exception(esc_html("Field {$field} must be at least {$rule['min_length']} characters"));
            }

            if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                throw new \Exception(esc_html("Field {$field} must not exceed {$rule['max_length']} characters"));
            }

            $validated[$field] = $value;
        }

        return $validated;
    }
    
    public function validateEmail(string $email): array
    {
        $email = trim($email);
        
        if (empty($email)) {
            return [
                'valid' => false,
                'message' => 'Email address is required'
            ];
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'valid' => false,
                'message' => 'Invalid email format'
            ];
        }
        
        $dangerous_chars = ['<', '>', '"', "'", '&', '\n', '\r', '\t'];
        foreach ($dangerous_chars as $char) {
            if (strpos($email, $char) !== false) {
                return [
                    'valid' => false,
                    'message' => 'Email contains invalid characters'
                ];
            }
        }
        
        if (strlen($email) > 254) {
            return [
                'valid' => false,
                'message' => 'Email address is too long (maximum 254 characters)'
            ];
        }
        
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            $domain = $parts[1];
            $suggested_domain = $this->findSimilarDomain($domain);
            
            if ($suggested_domain !== $domain) {
                return [
                    'valid' => true,
                    'message' => 'Valid email address',
                    'suggestion' => $parts[0] . '@' . $suggested_domain
                ];
            }
        }
        
        return [
            'valid' => true,
            'message' => 'Valid email address'
        ];
    }
    
    public function validatePayPalClientId(string $clientId): array
    {
        return $this->validateByPayPalRule($clientId, 'paypal_client_id');
    }
    
    public function validatePayPalClientSecret(string $clientSecret): array
    {
        return $this->validateByPayPalRule($clientSecret, 'paypal_client_secret');
    }
    
    public function validatePayPalWebhookId(string $webhookId): array
    {
        $webhookId = strtoupper(trim($webhookId));
        return $this->validateByPayPalRule($webhookId, 'paypal_webhook_id');
    }
    
    public function validateField(string $field, string $value, string $context = ''): array
    {
        switch ($field) {
            case 'email':
            case 'businessEmail':
            case 'paypalEmail':
                return $this->validateEmail($value);
                
            case 'clientId':
                return $this->validatePayPalClientId($value);
                
            case 'clientSecret':
                return $this->validatePayPalClientSecret($value);
                
            case 'webhookId':
                return $this->validatePayPalWebhookId($value);
                
            default:
                return $this->validateGeneric($value, $context);
        }
    }
    
    private function validateByPayPalRule(string $value, string $rule_name): array
    {
        $value = trim($value);
        
        if (!isset(self::PAYPAL_RULES[$rule_name])) {
            return [
                'valid' => false,
                'message' => 'Unknown validation rule'
            ];
        }
        
        $rule = self::PAYPAL_RULES[$rule_name];
        
        if (empty($value)) {
            return [
                'valid' => false,
                'message' => 'Value is required'
            ];
        }
        
        $length = strlen($value);
        if (isset($rule['min_length']) && $length < $rule['min_length']) {
            return [
                'valid' => false,
                'message' => "Value must be at least {$rule['min_length']} characters long"
            ];
        }
        
        if (isset($rule['max_length']) && $length > $rule['max_length']) {
            return [
                'valid' => false,
                'message' => "Value must not exceed {$rule['max_length']} characters"
            ];
        }
        
        if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
            return [
                'valid' => false,
                'message' => $rule['message']
            ];
        }
        
        return [
            'valid' => true,
            'message' => 'Valid input'
        ];
    }
    
    private function validateGeneric(string $value, string $context): array
    {
        $value = trim($value);
        
        $dangerous_patterns = [
            '/<script[^>]*>.*?<\/script>/i',
            '/javascript:/i',
            '/data:text\/html/i',
            '/vbscript:/i',
            '/on\w+\s*=/i'
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return [
                    'valid' => false,
                    'message' => 'Input contains potentially dangerous content'
                ];
            }
        }
        
        return [
            'valid' => true,
            'message' => 'Valid input'
        ];
    }
    
    private function findSimilarDomain(string $domain): string
    {
        $domain = strtolower($domain);
        $min_distance = PHP_INT_MAX;
        $suggested_domain = $domain;
        
        foreach (self::COMMON_DOMAINS as $common_domain) {
            $distance = levenshtein($domain, $common_domain);
            
            if ($distance <= 2 && $distance < $min_distance) {
                $min_distance = $distance;
                $suggested_domain = $common_domain;
            }
        }
        
        return $suggested_domain;
    }
    
    public function sanitizeByType(string $input, string $type): string
    {
        $input = trim($input);
        
        switch ($type) {
            case 'email':
                return sanitize_email($input);
                
            case 'url':
                return esc_url_raw($input);
                
            case 'alphanumeric':
                return preg_replace('/[^a-zA-Z0-9_-]/', '', $input);
                
            case 'numeric':
                return preg_replace('/[^0-9.]/', '', $input);
                
            case 'text':
                return sanitize_text_field($input);
                
            case 'textarea':
                return sanitize_textarea_field($input);
                
            default:
                return sanitize_text_field($input);
        }
    }
}