/**
 * Shared jQuery bootstrapper for Vite-built (dashboard-v3) pages.
 *
 * Ensures jQuery is attached to window before any plugins execute.
 */
import $ from 'jquery';

// Expose globally for plugins that expect a global jQuery
window.$ = window.jQuery = $;

export default $;
