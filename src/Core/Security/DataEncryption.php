<?php
namespace WalletUpPro\Core\Security;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class DataEncryption
{
    private static ?self $instance = null;
    private $key;
    private $cipher = 'aes-256-gcm';
    private $tagLength = 16;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        $this->key = $this->getEncryptionKey();
    }

    /** @var string[] Fallback keys to try if primary decryption fails (populated lazily) */
    private $fallbackKeys = [];

    private function getEncryptionKey(): string
    {
        if (defined('WALLETUP_ENCRYPTION_KEY') && !empty(WALLETUP_ENCRYPTION_KEY)) {
            $this->buildFallbackKeys('walletup_constant');
            return WALLETUP_ENCRYPTION_KEY;
        }

        if (defined('AUTH_KEY') && !empty(AUTH_KEY)) {
            $this->buildFallbackKeys('auth_key');
            return hash('sha256', AUTH_KEY . 'walletup_encryption_v1', true);
        }

        $key = get_option('wallet_up_encryption_key');
        if (!$key) {
            $key = wp_generate_password(64, true, true);
            update_option('wallet_up_encryption_key', $key, false); // autoload=false
            if (function_exists('error_log')) {
                error_log('[WalletUp Security] WARNING: Encryption key stored in database. Define WALLETUP_ENCRYPTION_KEY in wp-config.php for PCI DSS compliance.');
            }
        }
        return hash('sha256', $key . 'walletup_db_key_v1', true);
    }

    private function buildFallbackKeys(string $primarySource): void
    {
        if ($primarySource !== 'db_key') {
            $dbKey = get_option('wallet_up_encryption_key');
            if ($dbKey) {
                $this->fallbackKeys[] = hash('sha256', $dbKey . 'walletup_db_key_v1', true);
            }
        }
        if ($primarySource === 'walletup_constant' && defined('AUTH_KEY') && !empty(AUTH_KEY)) {
            $this->fallbackKeys[] = hash('sha256', AUTH_KEY . 'walletup_encryption_v1', true);
        }
    }

    public function encrypt(string $data): string
    {
        $ivlen = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $tag = '';

        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            $this->tagLength
        );

        return base64_encode($iv . $tag . $encrypted);
    }

    public function decrypt(string $data): string|false
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }

        $ivlen = openssl_cipher_iv_length($this->cipher);
        if (strlen($decoded) < $ivlen + $this->tagLength) {
            return false;
        }

        $iv = substr($decoded, 0, $ivlen);
        $tag = substr($decoded, $ivlen, $this->tagLength);
        $ciphertext = substr($decoded, $ivlen + $this->tagLength);

        $result = openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($result !== false) {
            return $result;
        }

        foreach ($this->fallbackKeys as $fallbackKey) {
            $result = openssl_decrypt(
                $ciphertext,
                $this->cipher,
                $fallbackKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            if ($result !== false) {
                $reEncrypted = $this->encrypt($result);
                return $result;
            }
        }

        return false;
    }

    public static function getEncryptedOption(string $optionKey, string $default = ''): string
    {
        $value = get_option($optionKey, $default);
        if (empty($value)) {
            return $default;
        }
        try {
            $instance = self::getInstance();
            $decrypted = $instance->decrypt($value);
            if ($decrypted !== false && $decrypted !== '') {
                $reEncrypted = $instance->encrypt($decrypted);
                if ($reEncrypted !== $value) {
                    update_option($optionKey, $reEncrypted);
                }
                return $decrypted;
            }
        } catch (\Exception $e) {
        }
        return $value;
    }
}