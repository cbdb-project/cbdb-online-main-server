/**
 * Modern World Entry Point - Main Application
 *
 * This is for AdminLTE v3 + Vue 3 pages (Modern World)
 * Legacy World (AdminLTE 2) remains in resources/assets/js/
 */

// Import AdminLTE v3 CSS from NPM
import 'admin-lte/dist/css/adminlte.min.css';

// Import jQuery and expose globally (MUST be first)
import $ from 'jquery';
window.$ = window.jQuery = $;

// Import Bootstrap 4 (required by AdminLTE v3)
import 'bootstrap';

// Import AdminLTE v3 JS
import 'admin-lte';

// Import Axios for HTTP requests
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

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

// Signal that all global libraries are loaded
window.viteReady = true;
if (window.viteReadyCallbacks) {
    window.viteReadyCallbacks.forEach(fn => fn());
    window.viteReadyCallbacks = [];
}
