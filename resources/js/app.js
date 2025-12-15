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

// Set global defaults for all Select2 instances to use Bootstrap 4 theme
$.fn.select2.defaults.set('theme', 'bootstrap4');

// Person select2 helper (shared across dashboard-v3 pages)
const formatPersonLabel = (item) => {
    const parts = [];
    if (item.c_personid) {
        parts.push(item.c_personid);
    }
    if (item.c_name_chn) {
        parts.push(item.c_name_chn);
    }
    if (item.c_name) {
        parts.push(item.c_name);
    }
    const dynasty = item.c_dynasty_chn ? `（${item.c_dynasty_chn}）` : '';
    const zi = item.c_alt_name_chn_zi ? `，字：${item.c_alt_name_chn_zi}` : '';
    const hao = item.c_alt_name_chn_hao ? `，號：${item.c_alt_name_chn_hao}` : '';
    const addr = item.ADDR_c_name_chn ? `，籍：${item.ADDR_c_name_chn}` : '';
    return `${parts.join(' - ')}${dynasty}${zi}${hao}${addr}`.trim();
};

const fetchPersonOption = (id) => {
    if (!id) {
        return Promise.resolve(null);
    }
    return $.get('/api/name', { q: id, num: 1 }).then((data) => {
        const item = Array.isArray(data?.data) && data.data.length > 0 ? data.data[0] : null;
        if (!item) {
            return null;
        }
        return {
            id: item.c_personid,
            text: formatPersonLabel(item) || item.c_personid,
        };
    }).catch(() => null);
};

window.initPersonSelect = ($el, options = {}) => {
    const initialId = $el.data('initial-id');
    $el.select2({
        width: '100%',
        theme: 'bootstrap4',
        minimumInputLength: 1,
        ajax: {
            url: '/api/name',
            dataType: 'json',
            delay: 250,
            data: (params) => ({
                q: params.term,
                num: 20,
            }),
            processResults: (data) => {
                const rows = Array.isArray(data?.data) ? data.data : [];
                return {
                    results: rows.map((item) => ({
                        id: item.c_personid,
                        text: formatPersonLabel(item) || item.c_personid,
                    })),
                };
            },
        },
        ...options,
    });

    if (initialId) {
        fetchPersonOption(initialId).then((opt) => {
            if (opt) {
                const option = new Option(opt.text, opt.id, true, true);
                $el.append(option).trigger('change.select2');
            } else {
                $el.val(initialId).trigger('change.select2');
            }
        });
    }
};

window.formatPersonLabel = formatPersonLabel;
window.fetchPersonOption = fetchPersonOption;

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

// Import Vue components
import SelectVue from '../assets/js/components/Select.vue';
import Select2 from '../assets/js/components/Select2.vue';
import Select2Addr from '../assets/js/components/Select2Addr.vue';

// Make createApp globally available for pages that need it
window.createVueApp = createApp;

// Install global modal focus guard when DOM is ready
$(installModalFocusFix);

// Auto-mount Vue app if #app element exists, then signal readiness
$(function() {
    const appElement = document.getElementById('app');
    if (appElement) {
        const app = createApp({
            components: {
                'select-vue': SelectVue,
                'select2': Select2,
                'select2-addr': Select2Addr,
            }
        });
        app.mount('#app');

        // Store app instance globally for debugging
        window.vueApp = app;
    }

    // Only mark Vite ready after DOM is ready and Vue (if any) has mounted.
    // This ensures onViteReady callbacks run after custom elements (e.g. <select-vue>) exist.
    window.viteReady = true;
    if (window.viteReadyCallbacks) {
        window.viteReadyCallbacks.forEach(fn => fn());
        window.viteReadyCallbacks = [];
    }
});
