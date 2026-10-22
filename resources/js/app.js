import './bootstrap';
import './accessibility';
import './auth-portal';
import './svgbet-slip';
import './svgdeposit';
import './svgwithdraw';

// Global application bootstrap and live notification dispatchers
window.addEventListener('DOMContentLoaded', () => {
    // Accessibility announcement on page load for screen readers
    if (window.TLA11y) {
        window.TLA11y.setupLiveRegion();
    }
});
