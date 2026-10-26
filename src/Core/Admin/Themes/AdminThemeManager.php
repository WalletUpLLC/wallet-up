<?php
namespace WalletUpPro\Core\Admin\Themes;

use WalletUpPro\Core\Error\ErrorHandler;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
class AdminThemeManager
{
    private static $instance = null;
    private $errorHandler;
    private $option_name = 'wallet_up_admin_theme_enabled';
    private $theme_css_file = 'assets/css/wallet-up-custom-style-admin.css';
    private $theme_state = null;

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->errorHandler = ErrorHandler::getInstance();

        add_action('init', [$this, 'checkThemeState'], 1);

        add_action('wp_ajax_wallet_up_toggle_admin_theme', [$this, 'handleThemeToggle']);
        add_action('wp_ajax_wallet_up_get_theme_state', [$this, 'getThemeState']);
    }

    public function checkThemeState(): void
    {
        $this->loadThemeState();

        if ($this->isThemeEnabled()) {
            $this->addThemeHooks();
        } else {
            $this->removeThemeHooks();
        }
    }

    private function addThemeHooks(): void
    {

        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminStyles'], 99);
        add_action('admin_head', [$this, 'applyAdminCustomizations'], 99);
        add_filter('admin_body_class', [$this, 'addCustomBodyClass']);
        add_action('admin_menu', [$this, 'customizeAdminMenu'], 99);
        add_filter('wp_nav_menu_args', [$this, 'customMenuArgs']);

        if (!is_admin()) {
            add_filter('show_admin_bar', [$this, 'shouldShowAdminBar'], 99);
        }
    }

    private function removeThemeHooks(): void
    {

        remove_action('admin_enqueue_scripts', [$this, 'enqueueAdminStyles'], 99);
        remove_action('admin_head', [$this, 'applyAdminCustomizations'], 99);
        remove_filter('admin_body_class', [$this, 'addCustomBodyClass']);
        remove_action('admin_menu', [$this, 'customizeAdminMenu'], 99);
        remove_filter('wp_nav_menu_args', [$this, 'customMenuArgs']);
        
        remove_filter('show_admin_bar', [$this, 'shouldShowAdminBar'], 99);
    }

    private function loadThemeState(): void
    {
        if ($this->theme_state === null) {
            $value = get_option($this->option_name, false);
            $this->theme_state = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
    }

    public function isThemeEnabled(): bool
    {
        return $this->theme_state === true;
    }

    /**
     * Intelligently determine if admin bar should be shown
     * 
     * @param bool $show Current state
     * @return bool Whether to show admin bar
     */
    public function shouldShowAdminBar($show): bool
    {
        if (is_admin()) {
            return true;
        }

        if (current_user_can('administrator')) {
            return $show;
        }

        return false;
    }

    public function handleThemeToggle(): void
    {
        if (!check_ajax_referer('wallet_up_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        if (!$this->canManageTheme()) {
            $this->errorHandler->log('Permission denied');
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
        $enabled = isset($_POST['enabled']) && sanitize_text_field(wp_unslash($_POST['enabled'] ?? '')) === 'yes';

        update_option($this->option_name, $enabled);
        $this->theme_state = $enabled; // Update the local state
        $this->clearCache(); // Clear cache right after the update

        $verifiedState = get_option($this->option_name, false);
        if ($verifiedState != $enabled) {
            wp_send_json_error(['message' => 'Failed to update theme state']);
            return;
        }

        wp_send_json_success(['enabled' => $enabled, 'reload' => true]);
    }

    public function getThemeState(): void
    {
        if (!$this->canManageTheme()) {
            wp_send_json_error(['message' => 'Permission denied']);
            return;
        }

        $this->loadThemeState();

        wp_send_json_success([
            'enabled' => $this->isThemeEnabled()
        ]);
    }
    public function enqueueAdminStyles(): void
    {
        try {
            $version = filemtime(WALLET_UP_PATH . $this->theme_css_file);
            wp_enqueue_style(
                'wallet-up-admin-theme',
                WALLET_UP_URL . $this->theme_css_file,
                [],
                $version // Use file modification time as version
            );

            wp_add_inline_style('wallet-up-admin-theme', $this->getResponsiveStyles());
            wp_add_inline_script('common', $this->getSubmenuDirectionScript());

        } catch (\Exception $e) {
            $this->errorHandler->log('Failed to enqueue styles: ' . $e->getMessage());
        }
    }

    private function getSubmenuDirectionScript(): string
    {
        return <<<'JS'
(function(){
  function init(){
    var menu=document.getElementById('adminmenu');
    if(!menu){return;}
    menu.addEventListener('mouseover',function(e){
      var li=(e.target&&e.target.closest)?e.target.closest('li.menu-top'):null;
      if(!li||!menu.contains(li)){return;}
      var sub=li.querySelector('.wp-submenu');
      if(!sub){return;}
      var r=li.getBoundingClientRect();
      var h=sub.scrollHeight;
      var vh=window.innerHeight||document.documentElement.clientHeight;
      if((r.top+h)>(vh-6)&&(r.bottom-h)>6){li.classList.add('wu-open-up');}else{li.classList.remove('wu-open-up');}
    });
    menu.addEventListener('mouseleave',function(){
      var ups=menu.querySelectorAll('li.menu-top.wu-open-up');
      for(var i=0;i<ups.length;i++){ups[i].classList.remove('wu-open-up');}
    });
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
})();
JS;
    }

    public function applyAdminCustomizations(): void
    {
        try {
            add_action('admin_menu', function () {
                global $menu;
                if (!is_array($menu)) {
                    return;
                }

                foreach ($menu as $key => $item) {
                    if (isset($item[5])) {
                        $menu[$key][4] = $menu[$key][4] . ' wallet-up-menu-item';
                    }
                }
            });

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS output sanitized via wp_strip_all_tags
            echo '<style type="text/css">' . wp_strip_all_tags($this->getCustomStyles()) . '</style>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon URLs sanitized in buildIconMaskStyles; CSS only
            echo '<style type="text/css" id="wallet-up-icon-masks">' . $this->buildIconMaskStyles() . '</style>';

        } catch (\Exception $e) {
            $this->errorHandler->log('Failed to apply customizations: ' . $e->getMessage());
        }
    }

    private function buildIconMaskStyles(): string
    {
        global $menu;
        if (!is_array($menu)) {
            return '';
        }
        $out = '';
        foreach ($menu as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id   = isset($item[5]) ? (string) $item[5] : '';
            $icon = isset($item[6]) ? (string) $item[6] : '';
            if ($id === '' || $icon === '') {
                continue;
            }
            $isImage = strpos($icon, 'data:image') === 0
                || (strpos($icon, 'http') === 0 && (substr($icon, -4) === '.svg' || substr($icon, -4) === '.png'));
            if (!$isImage) {
                continue;
            }
            $sid = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
            if ($sid === '') {
                continue;
            }
            $url = str_replace(["'", '"', "\n", "\r", '\\', '<', '>'], '', $icon);
            $sel = 'body.wallet-up-admin-theme #' . $sid . ' .wp-menu-image.svg';
            $out .= $sel . '{background-image:none!important;filter:none!important;background-color:#674fbf!important;'
                . "-webkit-mask:url('" . $url . "') center/20px 20px no-repeat!important;"
                . "mask:url('" . $url . "') center/20px 20px no-repeat!important;}";
            $out .= 'body.wallet-up-admin-theme #' . $sid . ':hover .wp-menu-image.svg,'
                . 'body.wallet-up-admin-theme #' . $sid . '.opensub .wp-menu-image.svg{background-color:#674fbf!important;}';
            $out .= 'body.wallet-up-admin-theme #' . $sid . '.current .wp-menu-image.svg,'
                . 'body.wallet-up-admin-theme #' . $sid . '.wp-has-current-submenu .wp-menu-image.svg,'
                . 'body.wallet-up-admin-theme #' . $sid . '.wp-menu-open .wp-menu-image.svg{background-color:#ffffff!important;}';
        }
        return $out;
    }

    private function getResponsiveStyles(): string
    {
        return '
            html.wp-toolbar {
                background: #f6f8fa;
                background: 0 0;
            }
            @media only screen and (min-width:782px) {
                html.wp-toolbar { padding-top: 36px }
            }
            @media only screen and (min-width:961px) {
                html.wp-toolbar { padding-top: 61px }
            }
        ';
    }

    private function getCustomStyles(): string
    {
        return '
                float: right;
                margin: 0px 13px;
                border-bottom: solid 1px #949595;
                border-radius: 10px;
            }
                padding: 9px 0 0;
                background-color: #674fbf;
                border: solid 4px;
                border-radius: 20px;
            }
            .folded #adminmenu div.wp-menu-image {
                float: none;
                margin: 0;
                border: none;
                position: absolute;
                z-index: 25;
            }
        ';
    }

    public function customMenuArgs($args = ''): array
    {
        if (is_user_logged_in()) {
            if ('main_menu' == $args['theme_location']) {
                $args['menu'] = 'logged-in';
            }
        } else {
            if ('main_menu' == $args['theme_location']) {
                $args['menu'] = 'logged-out';
            }
        }
        return $args;
    }

    public function customizeAdminMenu(): void
    {
        try {
            global $menu;
            if (!is_array($menu)) {
                return;
            }

            foreach ($menu as $key => $item) {
                if (!empty($item[5])) {
                    $menu[$key][4] = trim($item[4] . ' wallet-up-admin-menu-item');
                }
            }
        } catch (\Exception $e) {
            $this->errorHandler->log('Failed to customize admin menu: ' . $e->getMessage());
        }
    }

    public function addCustomBodyClass($classes): string
    {
        return trim($classes . ' wallet-up-admin-theme'); // Always add this class
    }

    private function canManageTheme(): bool
    {
        return current_user_can('manage_options') &&
            check_ajax_referer('wallet_up_admin_nonce', 'nonce', false);
    }

    private function clearCache(): void
    {
        wp_cache_delete('alloptions', 'options');
    }

    public function cleanup(): void
    {
        try {
            $this->clearCache();
        } catch (\Exception $e) {
            $this->errorHandler->log('Theme cleanup failed: ' . $e->getMessage());
        }
    }
}