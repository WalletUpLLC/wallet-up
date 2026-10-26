import React from 'react';
import { createRoot } from 'react-dom/client';

const settings = window.wc.wcSettings.getSetting('walletUpCheckout', {});
const label = window.wp.htmlEntities.decodeEntities(settings.title) || 'WalletUpPro Pay';

const Content = () => {
    let container = document.getElementById('wallet-up-checkout-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'wallet-up-checkout-container';
        container.className = 'wallet-up-modern-wrapper';
        document.body.appendChild(container); // Append to the body or a more appropriate parent element
    }
    return container;
};

const Block_Gateway = {
    name: 'wallet_up', // Must match your gateway ID
    label: label,
    content: Object(window.wp.element.createElement)(Content, null),
    edit: Object(window.wp.element.createElement)(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: settings.supports || {},
};

window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);

const CheckoutPaymentDisplay = () => {
    return React.createElement('div', { className: 'wallet-up-checkout-display' }, 'Payment Display Here');
};

let rootInstance = null;

const initializeCheckout = () => {
    const checkoutContainer = document.getElementById('wallet-up-checkout-container');
    
    if (checkoutContainer) {
        if (rootInstance) {
            rootInstance.unmount();
            rootInstance = null;
        }
        
        rootInstance = createRoot(checkoutContainer);
        rootInstance.render(
            React.createElement(React.StrictMode, null,
                React.createElement(CheckoutPaymentDisplay)
            )
        );
    } else {
        console.error("❌ wallet-up-checkout-container not found in checkout page.");
    }
};

const initialize = () => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeCheckout);
    } else {
        if (!document.getElementById('wallet-up-checkout-container')) {
            Content(); // Call Content to create the container
        }
        initializeCheckout();
    }

    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', () => {
            if (window.removeWalletUpProCheckout) {
                window.removeWalletUpProCheckout();
            }
            initializeCheckout();
        });
    }
};

window.removeWalletUpProCheckout = () => {
    if (rootInstance) {
        rootInstance.unmount();
        rootInstance = null;
    }
};

initialize();

if (!window.React) {
    window.React = React;
}

if (!window.ReactDOM) {
    window.ReactDOM = {
        createRoot: createRoot,
        render: function(element, container) {
            const root = createRoot(container);
            root.render(element);
            return {
                unmount: function() {
                    root.unmount();
                }
            };
        }
    };
}