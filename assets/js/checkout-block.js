import React from 'react';
import { createRoot } from 'react-dom/client';
import CheckoutPaymentDisplay from '/walup-react/src/components/payment/CheckoutPaymentDisplay.jsx';

document.addEventListener('DOMContentLoaded', () => {
    const checkoutContainer = document.getElementById('wallet-up-checkout-container');

    if (!checkoutContainer) {
        console.error("❌ wallet-up-checkout-container NOT found in checkout page.");
        return;
    }

    const root = createRoot(checkoutContainer);
    root.render(<CheckoutPaymentDisplay />);
});