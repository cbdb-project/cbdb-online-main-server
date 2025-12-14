/**
 * Modern World Entry Point - Passport
 *
 * This file is PURE ESM and will be built by Vite.
 * NO require(), NO AdminLTE, NO jQuery plugins.
 */

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

// Create Vue app with only Passport components
const app = createApp({
    components: {
        'passport-clients': PassportClients,
        'passport-authorized-clients': PassportAuthorizedClients,
        'passport-personal-access-tokens': PassportPersonalAccessTokens,
    }
});

app.mount('#app');
