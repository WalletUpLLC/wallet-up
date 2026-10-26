<?php
namespace WalletUpPro\Core\Security;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class OutputSanitizer
{
    public function sanitize($data, string $context = 'html')
    {
        switch ($context) {
            case 'html':
                return $this->sanitizeHtml($data);
            case 'attr':
                return $this->sanitizeAttr($data);
            case 'js':
                return $this->sanitizeJs($data);
            case 'url':
                return $this->sanitizeUrl($data);
            case 'filename':
                return $this->sanitizeFilename($data);
            default:
                return $this->sanitizeHtml($data);
        }
    }

    private function sanitizeHtml($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeHtml'], $data);
        }
        return wp_kses_post($data);
    }

    private function sanitizeAttr($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeAttr'], $data);
        }
        return esc_attr($data);
    }

    private function sanitizeJs($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeJs'], $data);
        }
        return esc_js($data);
    }

    private function sanitizeUrl($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeUrl'], $data);
        }
        return esc_url_raw($data);
    }

    private function sanitizeFilename($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeFilename'], $data);
        }
        return sanitize_file_name($data);
    }
}