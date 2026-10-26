<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file variables are not true globals.
defined('ABSPATH') || exit;
?>

<div id="wallet-up-payment-methods">
    <h3><?php esc_html_e('Select Payment Method', 'walletup'); ?></h3>

    <?php if (empty($methods)): ?>
        <p class="wallet-up-no-methods"><?php esc_html_e('No payment PMS methods available.', 'walletup'); ?></p>
    <?php else: ?>
        <ul class="wallet-up-method-list">
            <?php foreach ($methods as $method_id => $method): ?>
                <li class="wallet-up-method-item">
                    <input type="radio" id="wallet-up-method-<?php echo esc_attr($method_id); ?>"
                        name="wallet_up_payment_method" value="<?php echo esc_attr($method_id); ?>"
                        class="wallet-up-method-input" />
                    <label for="wallet-up-method-<?php echo esc_attr($method_id); ?>" class="wallet-up-method-label">
                        <?php if (!empty($method['icon'])): ?>
                            <img src="<?php echo esc_url($method['icon']); ?>" alt="<?php echo esc_attr($method['name']); ?>"
                                class="wallet-up-method-icon" />
                        <?php endif; ?>
                        <span class="wallet-up-method-name"><?php echo esc_html($method['name']); ?></span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div id="wallet-up-payment-display" class="wallet-up-payment-display"></div>

<?php
wp_nonce_field('wallet_up_wc_payment_display', 'wallet_up_nonce');
?>