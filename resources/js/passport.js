/**
 * Modern World Entry Point - Passport
 *
 * This file is PURE ESM and will be built by Vite.
 * NO require(), NO AdminLTE, NO jQuery plugins.
 */

// jQuery + Bootstrap are required for the legacy Passport modals
import $ from './jquery-global';
import 'admin-lte/plugins/bootstrap/js/bootstrap.bundle';

import { createApp } from 'vue';
import axios from 'axios';

// Axios setup (Modern World doesn't pollute window)
const axiosInstance = axios.create({
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
    }
});

// CSRF token
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    axiosInstance.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

// Make axios available globally for backward compatibility
window.axios = axiosInstance;

// Import Passport components
import PassportClients from './components/passport/Clients.vue';
import PassportAuthorizedClients from './components/passport/AuthorizedClients.vue';
import PassportPersonalAccessTokens from './components/passport/PersonalAccessTokens.vue';

// Mount Passport app after Vite bootstrap (ensures jQuery/Bootstrap modal plugins are ready)
const mountPassportApp = () => {
    const mountTarget = document.getElementById('passport-app');
    if (!mountTarget) {
        return;
    }

    const app = createApp({
        components: {
            'passport-clients': PassportClients,
            'passport-authorized-clients': PassportAuthorizedClients,
            'passport-personal-access-tokens': PassportPersonalAccessTokens,
        }
    });

    app.mount(mountTarget);
};

if (window.onViteReady) {
    window.onViteReady(mountPassportApp);
} else {
    mountPassportApp();
}
