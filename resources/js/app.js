/**
 * Modern World Entry Point - Main Application
 *
 * This is for AdminLTE v3 + Vue 3 pages (Modern World)
 * Legacy World (AdminLTE 2) remains in resources/assets/js/
 */

// Import AdminLTE v3 CSS from NPM
import 'admin-lte/dist/css/adminlte.min.css';

// Import jQuery and expose globally before any plugins run
import $ from './jquery-global';

// Import Bootstrap 4 bundle (from AdminLTE vendor, includes Popper)
import 'admin-lte/plugins/bootstrap/js/bootstrap.bundle';

// Import AdminLTE v3 JS
import 'admin-lte';

// Select2 (jQuery plugin) for enhanced selects on dashboard-v3 pages
import 'select2/dist/css/select2.min.css';
import '@ttskch/select2-bootstrap4-theme/dist/select2-bootstrap4.min.css';
import select2 from 'select2';
select2(window, $);

// Import Axios for HTTP requests
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Global modal focus management to avoid aria-hidden warnings when closing
const installModalFocusFix = () => {
    if (window.modalFocusFixInstalled) {
        return;
    }
    window.modalFocusFixInstalled = true;

    $(document).on('show.bs.modal', '.modal', function(event) {
        $(this).data('trigger-element', event ? event.relatedTarget || document.activeElement : document.activeElement);
    });

    $(document).on('hide.bs.modal', '.modal', function() {
        // Prevent Bootstrap from forcing focus back into a hiding modal
        $(document).off('focusin.bs.modal');

        const active = document.activeElement;
        if (active && this.contains(active)) {
            active.blur();
        }

        const hadBodyTabIndex = document.body.hasAttribute('tabindex');
        $(this).data('had-body-tabindex', hadBodyTabIndex);
        if (!hadBodyTabIndex) {
            document.body.setAttribute('tabindex', '-1');
        }
        document.body.focus({ preventScroll: true });
    });

    $(document).on('hidden.bs.modal', '.modal', function() {
        const trigger = $(this).data('trigger-element');
        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus({ preventScroll: true });
        } else {
            document.body.focus({ preventScroll: true });
        }

        const hadBodyTabIndex = $(this).data('had-body-tabindex');
        if (!hadBodyTabIndex) {
            document.body.removeAttribute('tabindex');
        }
    });
};

// CSRF token setup
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found');
}

// Vue 3 setup (for pages that need Vue components)
import { createApp } from 'vue';

// Make createApp globally available for pages that need it
window.createVueApp = createApp;

// Install global modal focus guard when DOM is ready
$(installModalFocusFix);

// Signal that all global libraries are loaded
window.viteReady = true;
if (window.viteReadyCallbacks) {
    window.viteReadyCallbacks.forEach(fn => fn());
    window.viteReadyCallbacks = [];
}
